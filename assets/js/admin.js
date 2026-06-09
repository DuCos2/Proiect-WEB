(async () => {
    const user = await loadCurrentUser();
    if (!user || user.role !== 'admin') {
        window.location.replace("index.html");
        return;
    }

    initAdminPanel();
})();

function showToast(message) {
    const container = document.getElementById("toast-container");
    const toast = document.createElement("div");
    toast.className = "toast";
    toast.textContent = message;
    container.append(toast);
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

function initAdminPanel() {
    const tabUsers = document.getElementById("tab-users");
    const tabEvents = document.getElementById("tab-events");
    const tabLocations = document.getElementById("tab-locations");
    const panelUsers = document.getElementById("panel-users");
    const panelEvents = document.getElementById("panel-events");
    const panelLocations = document.getElementById("panel-locations");
    const searchInput = document.getElementById("admin-search");
    const locationForm = document.getElementById("location-form");
    const sportsOptions = document.getElementById("admin-sports-options");

    let usersData = [];
    let eventsData = [];
    let locationsData = [];
    let sportsData = [];
    let activeTab = "users";

    tabUsers.addEventListener("click", () => {
        tabUsers.classList.add("active");
        tabEvents.classList.remove("active");
        tabLocations.classList.remove("active");
        panelUsers.hidden = false;
        panelEvents.hidden = true;
        panelLocations.hidden = true;
        activeTab = "users";
        searchInput.placeholder = "Search by name or email...";
        filterAndRender();
    });

    tabEvents.addEventListener("click", () => {
        tabEvents.classList.add("active");
        tabUsers.classList.remove("active");
        tabLocations.classList.remove("active");
        panelUsers.hidden = true;
        panelEvents.hidden = false;
        panelLocations.hidden = true;
        activeTab = "events";
        searchInput.placeholder = "Search by title, sport, or location...";
        filterAndRender();
    });

    tabLocations.addEventListener("click", () => {
        tabLocations.classList.add("active");
        tabUsers.classList.remove("active");
        tabEvents.classList.remove("active");
        panelUsers.hidden = true;
        panelEvents.hidden = true;
        panelLocations.hidden = false;
        activeTab = "locations";
        searchInput.placeholder = "Search by name, area, address, or sport...";
        filterAndRender();
    });

    searchInput.addEventListener("input", filterAndRender);

    async function loadData() {
        try {
            const [usersRes, eventsRes, locationsRes, sportsRes] = await Promise.all([
                apiRequest("backend/public/admin/index.php?route=users"),
                apiRequest("backend/public/admin/index.php?route=events"),
                apiRequest("backend/public/admin/index.php?route=locations"),
                apiRequest("backend/public/admin/index.php?route=sports")
            ]);

            usersData = usersRes.users || [];
            eventsData = eventsRes.events || [];
            locationsData = locationsRes.locations || [];
            sportsData = sportsRes.sports || [];

            renderSportOptions();
            filterAndRender();
        } catch (err) {
            showToast("Error loading administration data.");
        }
    }

    function renderSportOptions() {
        if (!sportsOptions) {
            return;
        }

        sportsOptions.replaceChildren();

        if (sportsData.length === 0) {
            const empty = document.createElement("span");
            empty.className = "admin-form-hint";
            empty.textContent = "No sports available.";
            sportsOptions.append(empty);
            return;
        }

        sportsData.forEach(sport => {
            const label = document.createElement("label");
            const checkbox = document.createElement("input");
            const text = document.createElement("span");

            label.className = "admin-sport-option";
            checkbox.type = "checkbox";
            checkbox.name = "sport_ids";
            checkbox.value = String(sport.id);
            text.textContent = sport.name;

            label.append(checkbox, text);
            sportsOptions.append(label);
        });
    }

    function filterAndRender() {
        const query = searchInput.value.toLowerCase().trim();

        if (activeTab === "users") {
            const filtered = usersData.filter(u =>
                (u.username || '').toLowerCase().includes(query) ||
                (u.email || '').toLowerCase().includes(query)
            );
            renderUsers(filtered);
        } else if (activeTab === "events") {
            const filtered = eventsData.filter(e =>
                (e.title || '').toLowerCase().includes(query) ||
                (e.sport || '').toLowerCase().includes(query) ||
                (e.location.name || '').toLowerCase().includes(query)
            );
            renderEvents(filtered);
        } else {
            const filtered = locationsData.filter(loc =>
                (loc.name || '').toLowerCase().includes(query) ||
                (loc.area || '').toLowerCase().includes(query) ||
                (loc.address || '').toLowerCase().includes(query) ||
                (loc.sports || '').toLowerCase().includes(query)
            );
            renderLocations(filtered);
        }
    }

    function renderUsers(users) {
        const tbody = document.getElementById("users-table-body");
        tbody.replaceChildren();

        if (users.length === 0) {
            const tr = document.createElement("tr");
            const td = document.createElement("td");
            td.colSpan = 6;
            td.style.textAlign = "center";
            td.textContent = "No users found.";
            tr.append(td);
            tbody.append(tr);
            return;
        }

        users.forEach(u => {
            const tr = document.createElement("tr");

            const tdUsername = document.createElement("td");
            tdUsername.textContent = u.username;

            const tdEmail = document.createElement("td");
            tdEmail.textContent = u.email;

            const tdRole = document.createElement("td");
            const badgeRole = document.createElement("span");
            badgeRole.className = `badge badge-${u.role}`;
            badgeRole.textContent = u.role;
            tdRole.append(badgeRole);

            const tdStatus = document.createElement("td");
            const badgeStatus = document.createElement("span");
            badgeStatus.className = u.is_banned == 1 ? 'badge badge-banned' : 'badge badge-active';
            badgeStatus.textContent = u.is_banned == 1 ? 'Banned' : 'Active';
            tdStatus.append(badgeStatus);

            const tdCreated = document.createElement("td");
            tdCreated.textContent = new Date(u.created_at).toLocaleDateString();

            const tdAction = document.createElement("td");
            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = u.is_banned == 1 ? 'btn btn-secondary' : 'btn btn-outline';
            btn.style.minHeight = '30px';
            btn.style.padding = '0.2rem 0.6rem';
            btn.textContent = u.is_banned == 1 ? 'Unban' : 'Ban';

            // Check if admin is modifying themselves
            const currentUserId = authState.user ? authState.user.id : null;
            if (currentUserId && currentUserId === u.id) {
                btn.disabled = true;
                btn.title = "You cannot ban yourself";
            }

            btn.addEventListener("click", async () => {
                btn.disabled = true;
                try {
                    const res = await apiRequest("backend/public/admin/index.php?route=user-ban", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({ id: u.id })
                    });
                    showToast(res.message);
                    await loadData();
                } catch (err) {
                    showToast(err.message || "Failed to update user status.");
                } finally {
                    btn.disabled = false;
                }
            });

            tdAction.append(btn);
            tr.append(tdUsername, tdEmail, tdRole, tdStatus, tdCreated, tdAction);
            tbody.append(tr);
        });
    }

    function renderEvents(events) {
        const tbody = document.getElementById("events-table-body");
        tbody.replaceChildren();

        if (events.length === 0) {
            const tr = document.createElement("tr");
            const td = document.createElement("td");
            td.colSpan = 7;
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

            const tdStatus = document.createElement("td");
            const badgeStatus = document.createElement("span");
            badgeStatus.className = e.status === 'banned' ? 'badge badge-banned' : 'badge badge-active';
            badgeStatus.textContent = e.status === 'banned' ? 'Banned' : 'Open';
            tdStatus.append(badgeStatus);

            const tdAction = document.createElement("td");
            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = e.status === 'banned' ? 'btn btn-secondary' : 'btn btn-outline';
            btn.style.minHeight = '30px';
            btn.style.padding = '0.2rem 0.6rem';
            btn.textContent = e.status === 'banned' ? 'Restore' : 'Ban';

            btn.addEventListener("click", async () => {
                btn.disabled = true;
                try {
                    const res = await apiRequest("backend/public/admin/index.php?route=event-ban", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({ id: e.id })
                    });
                    showToast(res.message);
                    await loadData();
                } catch (err) {
                    showToast(err.message || "Failed to update event status.");
                } finally {
                    btn.disabled = false;
                }
            });

            tdAction.append(btn);
            tr.append(tdTitle, tdSport, tdLoc, tdOrg, tdDate, tdStatus, tdAction);
            tbody.append(tr);
        });
    }

    function renderLocations(locations) {
        const tbody = document.getElementById("locations-table-body");
        tbody.replaceChildren();

        if (locations.length === 0) {
            const tr = document.createElement("tr");
            const td = document.createElement("td");
            td.colSpan = 5;
            td.style.textAlign = "center";
            td.textContent = "No locations found.";
            tr.append(td);
            tbody.append(tr);
            return;
        }

        locations.forEach(loc => {
            const tr = document.createElement("tr");

            const tdName = document.createElement("td");
            tdName.textContent = loc.name;

            const tdArea = document.createElement("td");
            tdArea.textContent = loc.area;

            const tdAddress = document.createElement("td");
            tdAddress.textContent = loc.address || "No address";

            const tdSports = document.createElement("td");
            tdSports.textContent = loc.sports || "No sports registered";

            const tdAction = document.createElement("td");
            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = "btn btn-outline";
            btn.style.minHeight = "30px";
            btn.style.padding = "0.2rem 0.6rem";
            btn.textContent = "Remove";

            btn.addEventListener("click", async () => {
                if (!window.confirm(`Remove ${loc.name}?`)) {
                    return;
                }

                btn.disabled = true;
                try {
                    const res = await apiRequest("backend/public/admin/index.php?route=location-delete", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({ id: loc.id })
                    });
                    showToast(res.message);
                    await loadData();
                } catch (err) {
                    showToast(err.message || "Failed to remove location.");
                } finally {
                    btn.disabled = false;
                }
            });

            tdAction.append(btn);
            tr.append(tdName, tdArea, tdAddress, tdSports, tdAction);
            tbody.append(tr);
        });
    }

    function setLocationFormMessage(message, type = "success") {
        const messageElement = document.querySelector("[data-admin-location-message]");

        if (!messageElement) {
            return;
        }

        messageElement.textContent = message;
        messageElement.classList.add("visible");
        messageElement.classList.toggle("error", type === "error");
    }

    function setLocationFormErrors(errors = {}) {
        locationForm.querySelectorAll("[name]").forEach(field => {
            const error = errors[field.name];

            field.removeAttribute("aria-invalid");
            field.removeAttribute("title");

            if (error) {
                field.setAttribute("aria-invalid", "true");
                field.setAttribute("title", error);
            }
        });
    }

    if (locationForm) {
        locationForm.addEventListener("submit", async (event) => {
            event.preventDefault();

            const submitButton = locationForm.querySelector("[type='submit']");
            const formData = new FormData(locationForm);
            const payload = Object.fromEntries(formData.entries());
            payload.sport_ids = formData.getAll("sport_ids");

            setLocationFormErrors();
            setLocationFormMessage("Saving location...");

            if (submitButton) {
                submitButton.disabled = true;
            }

            try {
                const res = await apiRequest("backend/public/admin/index.php?route=location-create", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify(payload)
                });

                locationForm.reset();
                showToast(res.message);
                setLocationFormMessage(res.message);
                await loadData();
            } catch (err) {
                const payloadErrors = err.payload || {};
                setLocationFormErrors(payloadErrors.errors);
                setLocationFormMessage(payloadErrors.message || err.message || "Failed to add location.", "error");
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                }
            }
        });
    }

    loadData();
}
