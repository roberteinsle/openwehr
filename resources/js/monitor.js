// Wachen-Monitor: the map of the alarm view. Leaflet with OpenStreetMap tiles, a big red point at the
// Einsatzstelle – no API key in the browser. Loaded only on /monitor (not part of app.js).
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('alarmMap', ({ lat, lng }) => ({
        map: null,
        init() {
            this.map = L.map(this.$refs.map, { zoomControl: false, attributionControl: true, dragging: false, scrollWheelZoom: false, doubleClickZoom: false, boxZoom: false, keyboard: false, touchZoom: false })
                .setView([lat, lng], 16);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            }).addTo(this.map);
            L.marker([lat, lng], {
                icon: L.divIcon({
                    className: '',
                    html: '<span style="display:block;width:38px;height:38px;border-radius:9999px;background:#ED2F12;border:5px solid #fff;box-shadow:0 0 0 4px #ED2F12,0 2px 10px rgba(0,0,0,.6)"></span>',
                    iconSize: [38, 38],
                    iconAnchor: [19, 19],
                }),
            }).addTo(this.map);
            // The container may get its final size only after layout
            setTimeout(() => this.map.invalidateSize(), 300);
        },
    }));
});
