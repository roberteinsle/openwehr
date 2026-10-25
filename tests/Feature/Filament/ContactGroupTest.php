<?php

use App\Filament\Resources\ContactGroupResource\Pages\CreateContactGroup;
use App\Filament\Resources\ContactGroupResource\Pages\ListContactGroups;
use App\Filament\Resources\ContactResource\Pages\CreateContact;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Group;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->admin()->create();
});

test('the Ansprechpartner page shows the Wehr by Abteilung first, then the free groups in their order', function () {
    $jf = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
    $extern = ContactGroup::create(['name' => 'Extern', 'sort_order' => 2]);
    $gemeinde = ContactGroup::create(['name' => 'Gemeinde', 'sort_order' => 1]);
    Contact::create(['role' => 'Leitstelle', 'name' => 'Leitstelle Musterkreis', 'phone' => '0123 112', 'contact_group_id' => $extern->id, 'sort_order' => 1]);
    Contact::create(['role' => 'Wehrführer', 'name' => 'Bernd Blaulicht', 'sort_order' => 2]);
    Contact::create(['role' => 'Bürgermeisterin', 'name' => 'Gerda Gemeinde', 'contact_group_id' => $gemeinde->id, 'group_id' => $jf->id, 'sort_order' => 3]);
    Contact::create(['role' => 'Jugendwartin', 'name' => 'Hanna Hydrant', 'group_id' => $jf->id, 'sort_order' => 4]);

    $this->actingAs(User::factory()->create())->get(route('contacts'))->assertOk()
        ->assertSeeInOrder(['Wehrführer', 'Jugendfeuerwehr', 'Jugendwartin', 'Gemeinde', 'Bürgermeisterin', 'Extern', 'Leitstelle Musterkreis']);
});

test('the Dienstplan leaves out groups that are switched off for it', function () {
    $extern = ContactGroup::create(['name' => 'Extern', 'on_roster' => false]);
    $wehr = ContactGroup::create(['name' => 'Wehrführung']);
    Contact::create(['role' => 'Leitstelle', 'name' => 'Leitstelle Musterkreis', 'contact_group_id' => $extern->id]);
    Contact::create(['role' => 'Wehrführer', 'name' => 'Bernd Blaulicht', 'contact_group_id' => $wehr->id]);
    Contact::create(['role' => 'Jugendwartin', 'name' => 'Hanna Hydrant']);

    expect(Contact::forRoster()->pluck('name')->sort()->values()->all())->toBe(['Bernd Blaulicht', 'Hanna Hydrant']);
});

test('admins create groups, assign contacts and deleting a group keeps its contacts', function () {
    $this->actingAs($this->admin);
    Livewire::test(CreateContactGroup::class)->fillForm(['name' => 'Extern', 'on_roster' => false])->call('create')->assertHasNoFormErrors();
    $group = ContactGroup::firstOrFail();
    expect($group->on_roster)->toBeFalse();

    Livewire::test(CreateContact::class)
        ->fillForm(['role' => 'Kreisbrandmeister', 'name' => 'Karl Kreis', 'contact_group_id' => $group->id])->call('create')->assertHasNoFormErrors();
    $contact = Contact::firstOrFail();
    expect($contact->contact_group_id)->toBe($group->id);

    Livewire::test(ListContactGroups::class)->assertSee('Extern')->callTableAction('delete', $group);
    expect($contact->fresh()->contact_group_id)->toBeNull();
});

test('only admins manage the groups', function () {
    $this->actingAs(User::factory()->create())->get('/admin/ansprechpartner-gruppen')->assertForbidden();
});
