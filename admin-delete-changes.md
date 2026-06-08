# Modificări Noi: Înlocuire Ban Event cu Delete Event

Acest ghid conține **doar** modificările care trebuie făcute pentru a schimba funcționalitatea de *Ban Event* cu cea de *Delete Event* permanent (în controler, rute, HTML și fișierul JS de administrare).

---

### 1. Modificare în `backend/src/Controllers/AdminController.php`
Înlocuiți vechea funcție `toggleEventBan()` din controler cu noua metodă de ștergere `deleteEvent()`:

*Cod nou de înlocuit:*
```php
    public function deleteEvent(): void
    {
        $payload = Request::jsonBody();
        $eventId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);

        if ($eventId === false || $eventId === null) {
            Response::json(['message' => 'Event ID is required.'], 422);
        }

        // Folosește metoda existentă delete() din modelul Event
        $this->events->delete($eventId);

        Response::json(['message' => 'Event deleted successfully.']);
    }
```

---

### 2. Modificare în `backend/public/admin/index.php` (Rute API)
Modificați ruta pentru evenimente din `event-ban` în `event-delete`:

*Cod nou de înlocuit:*
```php
// POST backend/public/admin/index.php?route=event-delete
$router->add('POST', 'event-delete', [$adminController, 'deleteEvent']);
```

---

### 3. Modificare în `admin.html` (Mapează doar 3 carduri de statistici și scoate coloana Status)

**A. Statistici (3 carduri în loc de 4):**
Înlocuiți secțiunea `<section class="admin-stats">` cu următoarea structură:
```html
  <section class="admin-stats">
    <div class="stat-card">
      <span id="stat-label-1">Total Users</span>
      <strong id="stat-total-users">...</strong>
    </div>
    <div class="stat-card">
      <span id="stat-label-2">Banned Users</span>
      <strong id="stat-banned-users">...</strong>
    </div>
    <div class="stat-card">
      <span id="stat-label-3">Total Events</span>
      <strong id="stat-total-events">...</strong>
    </div>
  </section>
```

**B. Tabel Evenimente (eliminare coloană Status):**
Înlocuiți tabelul de evenimente (`<div class="admin-table-wrapper" id="panel-events" hidden>`) cu următorul:
```html
    <!-- Events Tab Panel -->
    <div class="admin-table-wrapper" id="panel-events" hidden>
      <table class="admin-table">
        <thead>
          <tr>
            <th>Title</th>
            <th>Sport</th>
            <th>Location</th>
            <th>Organizer</th>
            <th>Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="events-table-body">
          <tr>
            <td colspan="6" style="text-align: center;">Loading events...</td>
          </tr>
        </tbody>
      </table>
    </div>
```

---

### 4. Modificare în `assets/js/admin.js` (Actualizare randare butoane și statistici)

**A. Funcția `updateStats()` (3 statistici în loc de 4):**
Înlocuiți funcția `updateStats()` cu următoarea:
```javascript
  function updateStats() {
    document.getElementById("stat-total-users").textContent = usersData.length;
    document.getElementById("stat-banned-users").textContent = usersData.filter(u => u.is_banned == 1).length;
    document.getElementById("stat-total-events").textContent = eventsData.length;
  }
```

**B. Funcția `renderEvents()` (buton de Delete permanent și confirmare):**
Înlocuiți întreaga funcție `renderEvents()` cu următoarea:
```javascript
  function renderEvents(events) {
    const tbody = document.getElementById("events-table-body");
    tbody.replaceChildren();

    if (events.length === 0) {
      const tr = document.createElement("tr");
      const td = document.createElement("td");
      td.colSpan = 6;
      td.style.textAlign = "center";
      td.textContent = "No events found.";
      tr.append(td);
      tbody.append(tr);
      return;
    }

    events.forEach(e => {
      const tr = document.createElement("tr");

      const tdTitle = document.createElement("td");
      tdTitle.textContent = e.title;

      const tdSport = document.createElement("td");
      tdSport.textContent = e.sport;

      const tdLoc = document.createElement("td");
      tdLoc.textContent = `${e.location.name} (${e.location.area})`;

      const tdOrg = document.createElement("td");
      tdOrg.textContent = e.organizer;

      const tdDate = document.createElement("td");
      tdDate.textContent = new Date(e.eventDate.replace(" ", "T")).toLocaleDateString();

      const tdAction = document.createElement("td");
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = 'btn btn-outline';
      btn.style.minHeight = '30px';
      btn.style.padding = '0.2rem 0.6rem';
      btn.textContent = 'Delete';

      btn.addEventListener("click", async () => {
        if (!confirm("Are you sure you want to permanently delete this event?")) {
          return;
        }
        btn.disabled = true;
        try {
          const res = await apiRequest("backend/public/admin/index.php?route=event-delete", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: e.id })
          });
          showToast(res.message);
          await loadData();
        } catch (err) {
          showToast(err.message || "Failed to delete event.");
        } finally {
          btn.disabled = false;
        }
      });

      tdAction.append(btn);
      tr.append(tdTitle, tdSport, tdLoc, tdOrg, tdDate, tdAction);
      tbody.append(tr);
    });
  }
```
