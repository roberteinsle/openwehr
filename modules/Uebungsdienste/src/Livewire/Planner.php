<?php

namespace Modules\Uebungsdienste\src\Livewire;

use App\Models\User;
use App\Services\VehicleBookingRegistry;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Models\Swap;
use Modules\Uebungsdienste\src\Services\ServicePlan;

/**
 * The Übungsdienstplan of a year: the next Dienst on top, the coming ones below (past ones on
 * request). Admins and Übungsdienstplaner change topic and responsible people right in the list (and
 * date, time, place on their own page); everyone else with access swaps their Dienst with another
 * responsible Kamerad, who has to agree. Waiting swaps are shown to everyone.
 */
class Planner extends Component
{
    #[Url(as: 'jahr')]
    public ?int $year = null;

    #[Url(as: 'vergangene', except: false)]
    public bool $showPast = false;

    public ?string $flash = null;

    /** The own Dienst a swap is being asked for, and the chosen "serviceId:userId" */
    public ?int $swapFrom = null;

    public string $swapWith = '';

    public function mount(): void
    {
        $this->year ??= ServicePlan::today()->year;
        $this->flash = session('uebungsdienste.flash');
    }

    private function service(int $id): Service
    {
        return ServicePlan::visibleTo(auth()->user())->findOrFail($id);
    }

    public function saveTopic(int $serviceId, ?string $topic, ServicePlan $plan): void
    {
        $this->attempt(fn () => $plan->setTopic($this->service($serviceId), $topic, auth()->user()));
    }

    public function addResponsible(int $serviceId, $userId, ServicePlan $plan): void
    {
        if (! $userId) {
            return;
        }

        $this->attempt(fn () => $plan->addResponsible($this->service($serviceId), User::findOrFail((int) $userId), auth()->user()));
    }

    public function removeResponsible(int $serviceId, int $userId, ServicePlan $plan): void
    {
        $this->attempt(fn () => $plan->removeResponsible($this->service($serviceId), $userId, auth()->user()));
    }

    public function startSwap(int $serviceId): void
    {
        $this->resetErrorBag();
        $this->swapFrom = $this->swapFrom === $serviceId ? null : $serviceId;
        $this->swapWith = '';
    }

    public function requestSwap(ServicePlan $plan): void
    {
        [$otherId, $userId] = array_map('intval', explode(':', $this->swapWith.':0'));
        if (! $otherId || ! $userId) {
            $this->addError('plan', 'Bitte wählen, mit wem du tauschen möchtest.');

            return;
        }
        $this->attempt(function () use ($plan, $otherId, $userId) {
            $swap = $plan->requestSwap(auth()->user(), $this->service((int) $this->swapFrom), $this->service($otherId), User::findOrFail($userId));
            $this->flash = 'Tausch angefragt – '.$swap->targetUser->name.' bekommt eine Nachricht und muss zustimmen.';
            $this->reset('swapFrom', 'swapWith');
        });
    }

    public function answerSwap(int $swapId, bool $accept, ServicePlan $plan): void
    {
        $this->attempt(function () use ($plan, $swapId, $accept) {
            $plan->answerSwap(auth()->user(), Swap::findOrFail($swapId), $accept);
            $this->flash = $accept ? 'Getauscht.' : 'Tausch abgelehnt.';
        });
    }

    public function withdrawSwap(int $swapId, ServicePlan $plan): void
    {
        $this->attempt(function () use ($plan, $swapId) {
            $plan->withdrawSwap(auth()->user(), Swap::findOrFail($swapId));
            $this->flash = 'Tauschanfrage zurückgezogen.';
        });
    }

    public function render(ServicePlan $plan)
    {
        $user = auth()->user();
        $today = ServicePlan::today();
        $visible = ServicePlan::visibleTo($user);
        $all = (clone $visible)->with(['responsible', 'ownerGroup', 'vehicles'])->whereYear('date', $this->year)->orderBy('date')->orderBy('starts_at')->get();
        $past = $all->filter(fn (Service $s) => $s->date->lt($today));
        $years = (clone $visible)->selectRaw('distinct extract(year from date)::int as y')->pluck('y')->push($today->year)->push($this->year)->unique()->sort()->values();
        $swaps = Swap::with(['requester', 'targetUser', 'service', 'targetService'])->where('status', 'pending')
            ->where(fn ($q) => $q->whereIn('service_id', $all->pluck('id'))->orWhereIn('target_service_id', $all->pluck('id')))->get();

        return view('uebungsdienste::livewire.planner', [
            'services' => $this->showPast ? $all : $all->diff($past)->values(),
            'pastCount' => $past->count(),
            'years' => $years,
            'next' => $plan->next($user),
            'today' => $today,
            'people' => User::active()->orderBy('name')->pluck('name', 'id'),
            'planner' => ServicePlan::canPlan($user),
            'editable' => $all->filter(fn (Service $s) => ServicePlan::mayEdit($user, $s))->pluck('id')->all(),
            'swaps' => $swaps,
            // Whom the own Dienst can be swapped with: coming Dienste with someone else responsible
            'swapOptions' => $this->swapFrom ? (clone $visible)->with('responsible')->whereDate('date', '>=', $today)->whereKeyNot($this->swapFrom)
                ->orderBy('date')->orderBy('starts_at')->get()->reject(fn (Service $s) => $s->responsible->contains('id', $user->id))
                ->flatMap(fn (Service $s) => $s->responsible->mapWithKeys(fn (User $u) => [$s->id.':'.$u->id => $s->dateLabel().' – '.$u->name])) : collect(),
            'vehicleNames' => app(VehicleBookingRegistry::class)->vehicles(),
        ]);
    }

    /** Runs a change and shows its validation message */
    private function attempt(callable $change): void
    {
        $this->resetErrorBag();

        try {
            $change();
        } catch (ValidationException $e) {
            $this->addError('plan', collect($e->errors())->flatten()->first());
        }
    }
}
