<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>{{ __('Nearby Parks') }} | ParkIQ</title>

    <link rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
          crossorigin="">

    <style>
        html, body { margin: 0; padding: 0; height: 100%; font-family: system-ui, sans-serif; }
        #map { width: 100%; height: 100vh; }
        .park-popup b { display: block; font-size: 16px; margin-bottom: 4px; }
        .park-popup .meta { color: #555; font-size: 13px; margin-bottom: 6px; }
        .park-popup a { color: #1a73e8; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>

<div id="map"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""></script>

<script>
    // Server-rendered park data (already validated/sanitized by the controller).
    const parks   = @json($parks);
    const userLoc = @json($userLocation); // { lat, lng } | null

    const map = L.map('map');

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap',
        maxZoom: 19,
    }).addTo(map);

    const bounds = [];
    const parkIcon = L.divIcon({
        className: 'park-icon',
        html: '<div style="background:#1a73e8;color:#fff;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;font-weight:700;box-shadow:0 2px 6px rgba(0,0,0,.3);border:2px solid #fff;">🅿️</div>',
        iconSize: [32, 32],
        iconAnchor: [16, 16],
    });

    parks.forEach((p, i) => {
        const marker = L.marker([p.lat, p.lng], { icon: parkIcon }).addTo(map);
        const directions = `https://www.google.com/maps/dir/?api=1&destination=${p.lat},${p.lng}`;
        marker.bindPopup(`
            <div class="park-popup">
                <b>${i + 1}. ${escapeHtml(p.name)}</b>
                <div class="meta">🅿️ ${p.free_spaces} مكان فارغ</div>
                <a href="${directions}" target="_blank" rel="noopener">🗺️ الاتجاهات</a>
            </div>
        `);
        bounds.push([p.lat, p.lng]);
    });

    if (userLoc) {
        L.circleMarker([userLoc.lat, userLoc.lng], {
            radius: 8,
            color: '#fff',
            weight: 2,
            fillColor: '#ea4335',
            fillOpacity: 1,
        }).addTo(map).bindPopup('📍 موقعك');
        bounds.push([userLoc.lat, userLoc.lng]);
    }

    if (bounds.length === 1) {
        map.setView(bounds[0], 15);
    } else if (bounds.length > 1) {
        map.fitBounds(bounds, { padding: [40, 40] });
    } else {
        map.setView([33.3152, 44.3661], 12); // fallback: Baghdad
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;',
        }[c]));
    }
</script>
</body>
</html>
