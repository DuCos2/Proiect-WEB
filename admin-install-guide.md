# Ghid Integrare Admin Panel & RSS & Securitate

Acest fișier conține toate codurile sursă pentru noile fișiere și modificările exacte pe care trebuie să le aplicați în proiect.

---

## 🆕 1. Fișiere Noi de Creat

### Fișierul A: `backend/config/admin.php`
Creați acest fișier pentru a defini adresa de mail cu statut de administrator.

```php
<?php

return [
    'admin_email' => 'log70302@gmail.com'
];
```

---

### Fișierul B: `backend/src/Support/Router.php`
Creați această clasă care se ocupă cu rutarea cererilor pentru punctele unice de intrare.

```php
<?php

namespace App\Support;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $route, callable $callback): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'route' => $route,
            'callback' => $callback,
        ];
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $route = $_GET['route'] ?? '';

        foreach ($this->routes as $r) {
            if ($r['method'] === $method && $r['route'] === $route) {
                call_user_func($r['callback']);
                return;
            }
        }

        Response::json(['message' => 'Action not found.'], 404);
    }
}
```

---

### Fișierul C: `backend/src/Controllers/AdminController.php`
Creați controlerul de administrare care restricționează accesul doar utilizatorilor cu rolul `admin` și se ocupă de listarea conturilor/evenimentelor, banarea conturilor și ștergerea evenimentelor.

```php
<?php

namespace App\Controllers;

use App\Models\User;
use App\Models\Event;
use App\Support\Auth;
use App\Support\Response;
use App\Support\Request;

final class AdminController
{
    public function __construct(private User $users, private Event $events)
    {
        $user = Auth::user($this->users);
        if ($user === null || $user['role'] !== 'admin') {
            Response::json(['message' => 'Access denied. Administrator privileges required.'], 403);
        }
    }

    public function listUsers(): void
    {
        $allUsers = $this->users->all();
        Response::json(['users' => $allUsers]);
    }

    public function toggleUserBan(): void
    {
        $payload = Request::jsonBody();
        $userId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);

        if ($userId === false || $userId === null) {
            Response::json(['message' => 'User ID is required.'], 422);
        }

        $admin = Auth::user($this->users);
        if ($admin !== null && (int)$admin['id'] === $userId) {
            Response::json(['message' => 'You cannot ban yourself.'], 400);
        }

        $newStatus = $this->users->toggleBan($userId);
        $message = $newStatus ? 'User banned successfully.' : 'User unbanned successfully.';

        Response::json(['message' => $message, 'is_banned' => $newStatus]);
    }

    public function listEvents(): void
    {
        $allEvents = $this->events->allAdmin();
        Response::json(['events' => $allEvents]);
    }

    public function deleteEvent(): void
    {
        $payload = Request::jsonBody();
        $eventId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);

        if ($eventId === false || $eventId === null) {
            Response::json(['message' => 'Event ID is required.'], 422);
        }

        $this->events->delete($eventId);

        Response::json(['message' => 'Event deleted successfully.']);
    }
}
```

---

### Fișierul D: `backend/public/admin/index.php`
Creați punctul unic de intrare pentru API-ul administrativ care folosește clasa `Router`.

```php
<?php

require_once __DIR__ . '/../../bootstrap.php';

use App\Support\Router;
use App\Controllers\AdminController;
use App\Models\User;
use App\Models\Event;
use App\Support\Database;

$pdo = Database::connection();
$adminController = new AdminController(new User($pdo), new Event($pdo));

$router = new Router();

// GET backend/public/admin/index.php?route=users
$router->add('GET', 'users', [$adminController, 'listUsers']);

// POST backend/public/admin/index.php?route=user-ban
$router->add('POST', 'user-ban', [$adminController, 'toggleUserBan']);

// GET backend/public/admin/index.php?route=events
$router->add('GET', 'events', [$adminController, 'listEvents']);

// POST backend/public/admin/index.php?route=event-delete
$router->add('POST', 'event-delete', [$adminController, 'deleteEvent']);

$router->dispatch();
```

---

### Fișierul E: `backend/public/rss.php`
Creați acest script PHP pentru a oferi un feed RSS valid în format XML cu evenimentele deschise.

```php
<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Models\Event;
use App\Support\Database;

header('Content-Type: application/xml; charset=utf-8');

$pdo = Database::connection();
$eventsModel = new Event($pdo);
$events = $eventsModel->all(); // Returnează doar evenimentele deschise

echo '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
echo '<rss version="2.0">' . "\n";
echo '  <channel>' . "\n";
echo '    <title>Local Greetings Events Feed</title>' . "\n";
echo '    <link>http://localhost/index.html</link>' . "\n";
echo '    <description>Latest sports events in Iasi</description>' . "\n";
echo '    <language>en-us</language>' . "\n";

foreach ($events as $event) {
    $title = htmlspecialchars($event['title']);
    $description = htmlspecialchars($event['description'] . ' - at ' . $event['location']['name'] . ' (' . $event['location']['area'] . ') on ' . $event['eventDate']);
    $link = htmlspecialchars("http://localhost/event-details.html?id=" . $event['id']);
    $guid = $event['id'];
    $pubDate = date(DATE_RSS, strtotime($event['createdAt']));

    echo '    <item>' . "\n";
    echo '      <title>' . $title . '</title>' . "\n";
    echo '      <description>' . $description . '</description>' . "\n";
    echo '      <link>' . $link . '</link>' . "\n";
    echo '      <guid isPermaLink="false">' . $guid . '</guid>' . "\n";
    echo '      <pubDate>' . $pubDate . '</pubDate>' . "\n";
    echo '    </item>' . "\n";
}

echo '  </channel>' . "\n";
echo '</rss>' . "\n";
```

---

### Fișierul F: `backend/scripts/promote-admin.php`
Creați acest utilitar CLI pentru a promova ușor din terminal orice adresă de email la statutul de admin.
*Mod de utilizare în terminal:* `php backend/scripts/promote-admin.php email@domeniu.com`

```php
<?php

if (php_sapi_name() !== 'cli') {
    die("This script can only be run via CLI.\n");
}

if ($argc < 2) {
    die("Usage: php promote-admin.php <email>\n");
}

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;

$email = trim($argv[1]);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die("Error: Invalid email format.\n");
}

$pdo = Database::connection();

// Check if user exists
$stmt = $pdo->prepare('SELECT id, role FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

if (!$user) {
    die("Error: User with email '{$email}' not found.\n");
}

// Update role to admin
$stmt = $pdo->prepare('UPDATE users SET role = "admin" WHERE id = :id');
$stmt->execute(['id' => $user['id']]);

echo "Success: User '{$email}' has been promoted to 'admin'.\n";
```

---

### Fișierul G: `admin.html`
Creați fișierul HTML al panoului administrativ în rădăcina proiectului.

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Panel | Local Greetings</title>
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body data-requires-auth>
<header class="site-header">
  <a class="brand" href="index.html" aria-label="Local Greetings">
    <img class="brand-logo" src="assets/images/app-logo.png" alt="">
    <span>
        <strong>Local Greetings</strong>
        <small>sports in Iasi</small>
      </span>
  </a>

  <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="main-nav">
    <span></span>
    <span></span>
    <span></span>
    <span class="sr-only">Menu</span>
  </button>

  <nav class="main-nav" id="main-nav" aria-label="Main navigation">
    <a href="index.html">Events</a>
    <a href="search.html">Search</a>
    <a href="login.html">Login</a>
    <a href="register.html">Register</a>
    <a href="profile.html" class="profile-link" aria-label="Profile">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4 0-7 2.1-7 5v1h14v-1c0-2.9-3-5-7-5Z"></path>
      </svg>
      Profile
    </a>
  </nav>
</header>

<main style="padding: 2rem 0;">
  <div style="margin-bottom: 2rem;">
    <p class="eyebrow">Administration</p>
    <h1>Control Center</h1>
    <p style="color: var(--muted); font-size: 1.1rem;">Manage users and moderate application events.</p>
  </div>

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

  <section>
    <div class="admin-tabs">
      <button class="admin-tab active" id="tab-users" type="button">Users</button>
      <button class="admin-tab" id="tab-events" type="button">Events</button>
    </div>

    <div class="search-filter-box">
      <input type="text" id="admin-search" placeholder="Search by name, email, or title..." class="btn-light" style="width: 100%; max-width: 400px; padding: 0.6rem 1rem;">
    </div>

    <!-- Users Tab Panel -->
    <div class="admin-table-wrapper" id="panel-users">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Username</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Joined At</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="users-table-body">
          <tr>
            <td colspan="6" style="text-align: center;">Loading users...</td>
          </tr>
        </tbody>
      </table>
    </div>

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
  </section>
</main>

<div class="toast-container" id="toast-container"></div>

<script src="assets/js/main.js"></script>
<script src="assets/js/admin.js"></script>
</body>
</html>
```

---

### Fișierul H: `assets/css/admin.css`
Creați acest fișier CSS nou în directorul `assets/css/` pentru a stiliza panoul de administrare.

```css
.admin-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1.5rem;
  margin-bottom: 2rem;
}
.stat-card {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 12px;
  padding: 1.5rem;
  box-shadow: 0 10px 30px rgba(29, 49, 47, 0.05);
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  transition: transform 0.2s ease;
}
.stat-card:hover {
  transform: translateY(-2px);
}
.stat-card strong {
  font-size: 2.2rem;
  color: var(--primary);
}
.stat-card span {
  color: var(--muted);
  font-weight: 700;
  text-transform: uppercase;
  font-size: 0.8rem;
}
.admin-tabs {
  display: flex;
  gap: 0.5rem;
  border-bottom: 2px solid var(--line);
  margin-bottom: 1.5rem;
}
.admin-tab {
  background: none;
  border: none;
  padding: 0.75rem 1.5rem;
  font-weight: 800;
  cursor: pointer;
  color: var(--muted);
  border-bottom: 3px solid transparent;
  transition: all 0.2s ease;
}
.admin-tab.active {
  color: var(--primary);
  border-bottom-color: var(--primary);
}
.search-filter-box {
  margin-bottom: 1.5rem;
}
.admin-table-wrapper {
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: 12px;
  overflow-x: auto;
  box-shadow: 0 10px 30px rgba(29, 49, 47, 0.05);
}
.admin-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}
.admin-table th, .admin-table td {
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--line);
}
.admin-table th {
  background: var(--surface-soft);
  font-weight: 800;
  color: var(--ink);
}
.badge {
  display: inline-flex;
  padding: 0.25rem 0.5rem;
  border-radius: 6px;
  font-size: 0.75rem;
  font-weight: 800;
  text-transform: uppercase;
}
.badge-active { background: #e2f9f3; color: #10b981; }
.badge-banned { background: #fee2e2; color: #ef4444; }
.badge-admin { background: #e0f2fe; color: #0284c7; }
.badge-user { background: #f3f4f6; color: #4b5563; }
.toast-container {
  position: fixed;
  bottom: 2rem;
  right: 2rem;
  z-index: 100;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.toast {
  background: var(--ink);
  color: #fff;
  padding: 1rem 1.5rem;
  border-radius: 8px;
  box-shadow: var(--shadow);
  font-weight: 800;
  animation: slideIn 0.3s ease forwards;
}
@keyframes slideIn {
  from { transform: translateY(1rem); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}
```

---

### Fișierul I: `assets/js/admin.js`
Creați acest fișier JS nou în directorul `assets/js/` pentru a controla comportamentul interactiv al panoului administrativ.

```javascript
// Quick verification of administrator status
(async () => {
  const user = await loadCurrentUser();
  if (!user || user.role !== 'admin') {
    window.location.replace("index.html");
    return;
  }

  // Initialize Admin Module
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
  const panelUsers = document.getElementById("panel-users");
  const panelEvents = document.getElementById("panel-events");
  const searchInput = document.getElementById("admin-search");

  let usersData = [];
  let eventsData = [];
  let activeTab = "users";

  tabUsers.addEventListener("click", () => {
    tabUsers.classList.add("active");
    tabEvents.classList.remove("active");
    panelUsers.hidden = false;
    panelEvents.hidden = true;
    activeTab = "users";
    filterAndRender();
  });

  tabEvents.addEventListener("click", () => {
    tabEvents.classList.add("active");
    tabUsers.classList.remove("active");
    panelUsers.hidden = true;
    panelEvents.hidden = false;
    activeTab = "events";
    filterAndRender();
  });

  searchInput.addEventListener("input", filterAndRender);

  async function loadData() {
    try {
      const [usersRes, eventsRes] = await Promise.all([
        apiRequest("backend/public/admin/index.php?route=users"),
        apiRequest("backend/public/admin/index.php?route=events")
      ]);

      usersData = usersRes.users || [];
      eventsData = eventsRes.events || [];

      updateStats();
      filterAndRender();
    } catch (err) {
      showToast("Error loading administration data.");
    }
  }

  function updateStats() {
    document.getElementById("stat-total-users").textContent = usersData.length;
    document.getElementById("stat-banned-users").textContent = usersData.filter(u => u.is_banned == 1).length;
    document.getElementById("stat-total-events").textContent = eventsData.length;
  }

  function filterAndRender() {
    const query = searchInput.value.toLowerCase().trim();

    if (activeTab === "users") {
      const filtered = usersData.filter(u => 
        (u.username || '').toLowerCase().includes(query) || 
        (u.email || '').toLowerCase().includes(query)
      );
      renderUsers(filtered);
    } else {
      const filtered = eventsData.filter(e => 
        (e.title || '').toLowerCase().includes(query) || 
        (e.sport || '').toLowerCase().includes(query) ||
        (e.location.name || '').toLowerCase().includes(query)
      );
      renderEvents(filtered);
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

  loadData();
}
```

---

## 🛠️ 2. Fișiere Existente de Modificat

### Fișierul 1: `backend/bootstrap.php`
Înlocuiți întregul conținut al fișierului `backend/bootstrap.php` cu acest cod care forțează verificarea globală CSRF pe cererile modificatoare (POST, PUT, DELETE).

```php
<?php

require_once __DIR__ . '/autoload.php';

use App\Support\Response;
use App\Support\Request;
use App\Support\Csrf;

set_exception_handler(function (Throwable $exception): void {
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1'], true);

    Response::json([
        'message' => $isLocal ? $exception->getMessage() : 'Server error.',
    ], 500);
});

// Enforce CSRF protection globally on modifying HTTP verbs
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    // Skip verification for the CSRF token endpoint itself
    if (strpos($uri, 'csrf.php') === false) {
        if (!Csrf::isValid(Request::csrfToken())) {
            Response::json(['message' => 'CSRF token is invalid or expired.'], 403);
        }
    }
}
```

---

### Fișierul 2: `backend/src/Support/Auth.php`
Înlocuiți conținutul `backend/src/Support/Auth.php` pentru a invalida automat sesiunea utilizatorilor blocați (`is_banned`).

```php
<?php

namespace App\Support;

use App\Models\User;

final class Auth
{
    public static function user(?User $users = null): ?array
    {
        $token = Request::bearerToken();

        if ($token !== null && $users instanceof User) {
            $user = $users->findByAuthTokenHash(self::tokenHash($token));
            if ($user !== null && (bool) $user['is_banned']) {
                return null; // Deconconectează conturile blocate instantaneu
            }
            return $user;
        }

        return null;
    }

    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }
}
```

---

### Fișierul 3: `backend/src/Models/User.php`
Înlocuiți complet conținutul fișierului `backend/src/Models/User.php` cu acest cod consolidat (care conține logica de atribuire automată a statutului de admin la înregistrare, metodele `all()` și `toggleBan()` de administrare și rezolvă eroarea cu `createAuthToken`).

```php
<?php

namespace App\Models;

use PDO;

final class User
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, email, password_hash, role, is_banned, email_verified_at FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    public function create(string $username, string $email, string $passwordHash): array
    {
        // Load admin configuration
        $adminConfigPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'admin.php';
        $adminEmail = 'log70302@gmail.com'; // fallback
        if (file_exists($adminConfigPath)) {
            $config = require $adminConfigPath;
            if (is_array($config) && isset($config['admin_email'])) {
                $adminEmail = $config['admin_email'];
            }
        }

        $role = (strtolower($email) === strtolower($adminEmail)) ? 'admin' : 'user';

        $statement = $this->pdo->prepare(
            'INSERT INTO users (username, email, password_hash, role, is_banned, created_at)
             VALUES (:username, :email, :password_hash, :role, 0, NOW())'
        );
        $statement->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
        ]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'is_banned' => 0,
            'email_verified_at' => null,
        ];
    }

    public function updatePasswordHash(int $id, string $passwordHash): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $statement->execute([
            'id' => $id,
            'password_hash' => $passwordHash,
        ]);
    }

    public function createAuthToken(int $userId, string $tokenHash): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 7 DAY))'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
        ]);
    }

    public function findByAuthTokenHash(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT users.id, users.username, users.email, users.role, users.is_banned, users.email_verified_at
             FROM auth_tokens
             INNER JOIN users ON users.id = auth_tokens.user_id
             WHERE auth_tokens.token_hash = :token_hash
               AND auth_tokens.expires_at > NOW()
               AND auth_tokens.revoked_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    public function revokeAuthToken(string $tokenHash): void
    {
        $statement = $this->pdo->prepare('UPDATE auth_tokens SET revoked_at = NOW() WHERE token_hash = :token_hash');
        $statement->execute(['token_hash' => $tokenHash]);
    }

    public function findLoginAttempt(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT email, attempt_count, locked_until
             FROM login_attempts
             WHERE email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $attempt = $statement->fetch();

        return $attempt ?: null;
    }

    public function saveLoginAttempt(string $email, int $attemptCount, ?string $lockedUntil): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (email, attempt_count, locked_until)
             VALUES (:email, :attempt_count, :locked_until)
             ON DUPLICATE KEY UPDATE
                 attempt_count = VALUES(attempt_count),
                 locked_until = VALUES(locked_until),
                 updated_at = NOW()'
        );
        $statement->execute([
            'email' => $email,
            'attempt_count' => $attemptCount,
            'locked_until' => $lockedUntil,
        ]);
    }

    public function deleteLoginAttempt(string $email): void
    {
        $statement = $this->pdo->prepare('DELETE FROM login_attempts WHERE email = :email');
        $statement->execute(['email' => $email]);
    }

    public function createEmailVerification(int $userId, string $tokenHash): void
    {
        $this->deleteEmailVerifications($userId);

        $statement = $this->pdo->prepare(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 DAY))'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
        ]);
    }

    public function verifyEmailByTokenHash(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT users.id, users.username, users.email, users.role, users.is_banned, users.email_verified_at
             FROM email_verifications
             INNER JOIN users ON users.id = email_verifications.user_id
             WHERE email_verifications.token_hash = :token_hash
               AND email_verifications.expires_at > NOW()
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $user = $statement->fetch();

        if (!$user) {
            return null;
        }

        $this->pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id')
            ->execute(['id' => $user['id']]);
        $this->deleteEmailVerifications((int) $user['id']);
        $user['email_verified_at'] = date('Y-m-d H:i:s');

        return $user;
    }

    public function createPasswordReset(int $userId, string $tokenHash): void
    {
        $this->pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL')
            ->execute(['user_id' => $userId]);

        $statement = $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
        ]);
    }

    public function findUserByPasswordResetTokenHash(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT users.id, users.username, users.email, users.password_hash, users.role, users.is_banned, users.email_verified_at
             FROM password_resets
             INNER JOIN users ON users.id = password_resets.user_id
             WHERE password_resets.token_hash = :token_hash
               AND password_resets.expires_at > NOW()
               AND password_resets.used_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash]);
        $user = $statement->fetch();

        return $user ?: null;
    }

    public function markPasswordResetUsed(string $tokenHash): void
    {
        $statement = $this->pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE token_hash = :token_hash');
        $statement->execute(['token_hash' => $tokenHash]);
    }

    private function deleteEmailVerifications(int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM email_verifications WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, email, role, is_banned, created_at FROM users ORDER BY created_at DESC'
        );
        $statement->execute();
        return $statement->fetchAll();
    }

    public function toggleBan(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT is_banned FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $isBanned = (bool) $statement->fetchColumn();

        $newStatus = !$isBanned;

        $statement = $this->pdo->prepare('UPDATE users SET is_banned = :is_banned WHERE id = :id');
        $statement->execute([
            'is_banned' => $newStatus ? 1 : 0,
            'id' => $id
        ]);

        if ($newStatus === true) {
            $statement = $this->pdo->prepare('UPDATE auth_tokens SET revoked_at = NOW() WHERE user_id = :user_id');
            $statement->execute(['user_id' => $id]);
        }

        return $newStatus;
    }
}
```

---

### Fișierul 4: `backend/src/Models/Event.php`
Adăugați metodele `allAdmin()` la sfârșitul clasei și introduceți validarea calendaristică împotriva suprapunerilor de evenimente în cadrul aceleiași locații (interval de 2 ore).

#### Modificarea A: Adăugați verificarea calendaristică în metoda `validate()`
Introduceți acest bloc de cod exact la sfârșitul metodei `validate()` (chiar înainte de linia `return $errors;`):

```php
        // Event calendar overlap verification (no other event at the same location within a 2-hour window)
        if (!isset($errors['event_date']) && !empty($payload['location']) && !empty($payload['event_date'])) {
            $locationName = $payload['location'];
            $eventDate = $this->normalizeDate($payload['event_date']);

            if ($eventDate !== null) {
                $statement = $this->pdo->prepare('
                    SELECT COUNT(*) FROM events 
                    INNER JOIN locations ON locations.id = events.location_id
                    WHERE LOWER(locations.name) = LOWER(:location)
                      AND (events.status IS NULL OR events.status = "open")
                      AND ABS(TIMESTAMPDIFF(MINUTE, events.event_date, :event_date)) < 120
                ');
                $statement->execute([
                    'location' => $locationName,
                    'event_date' => $eventDate
                ]);
                $overlapCount = (int) $statement->fetchColumn();

                if ($overlapCount > 0) {
                    $errors['event_date'] = 'There is already another event scheduled at this location within this time window (2 hours).';
                }
            }
        }
```

#### Modificarea B: Adăugați metoda `allAdmin()` în clasa `Event`
Adăugați această metodă în clasa `Event` (de exemplu, chiar înainte de acolada de închidere `}` a clasei):

```php
    public function allAdmin(): array
    {
        $statement = $this->pdo->prepare($this->baseSelect() . '
            ORDER BY events.event_date ASC, events.created_at DESC
        ');
        $statement->execute();

        return array_map(
            fn (array $event): array => $this->present($event, null),
            $statement->fetchAll()
        );
    }
```

---

### Fișierul 5: `backend/src/Controllers/EventController.php`
Înlocuiți metoda `show()` existentă cu aceasta, pentru a preveni accesarea detaliilor evenimentelor șterse de către utilizatori obișnuiți.

```php
    public function show(int $id): void
    {
        $user = Auth::user($this->users);
        $event = $this->events->find($id, $user !== null ? (int) $user['id'] : null);

        if ($event === null || ($event['status'] === 'banned' && ($user === null || $user['role'] !== 'admin'))) {
            Response::json(['message' => 'Event not found.'], 404);
        }

        Response::json(['event' => $event]);
    }
```

---

### Fișierul 6: `assets/js/main.js`

#### Modificarea A: Înlocuiți funcția `apiRequest` (în jurul liniei 35)
Această funcție va injecta automat header-ul `X-CSRF-Token` pe cererile modificatoare (POST, PUT, DELETE).

```javascript
const apiRequest = async (url, options = {}) => {
  const headers = {
    Accept: "application/json",
    ...(options.headers || {})
  };

  if (authState.token) {
    headers.Authorization = `Bearer ${authState.token}`;
  }

  const method = (options.method || "GET").toUpperCase();
  if (["POST", "PUT", "DELETE"].includes(method) && !url.includes("csrf.php")) {
    const csrfToken = await getCsrfToken();
    if (csrfToken) {
      headers["X-CSRF-Token"] = csrfToken;
    }
  }

  const response = await fetch(url, {
    credentials: "same-origin",
    ...options,
    headers
  });

  let payload = {};

  try {
    payload = await response.json();
  } catch (error) {
    payload = { message: "Unexpected server response." };
  }

  if (!response.ok) {
    const requestError = new Error(payload.message || "Request failed.");
    requestError.payload = payload;
    throw requestError;
  }

  return payload;
};
```

#### Modificarea B: Înlocuiți funcția `updateAuthNavigation` (în jurul liniei 145)
Injectează un link către `admin.html` în navigație pentru administratori.

```javascript
const updateAuthNavigation = async () => {
  if (!mainNav) {
    return;
  }

  const user = await loadCurrentUser();
  const loginLink = mainNav.querySelector('a[href="login.html"]');
  const registerLink = mainNav.querySelector('a[href="register.html"]');
  const profileLink = mainNav.querySelector('a[href="profile.html"]');
  let logoutButton = mainNav.querySelector(".nav-logout");
  let adminLink = mainNav.querySelector('a[href="admin.html"]');

  if (user) {
    if (loginLink) {
      loginLink.hidden = true;
    }

    if (registerLink) {
      registerLink.hidden = true;
    }

    if (!logoutButton) {
      logoutButton = createLogoutButton();
      mainNav.insertBefore(logoutButton, profileLink);
    }

    logoutButton.hidden = false;

    // Handle Admin Panel Navigation Link
    if (user.role === 'admin') {
      if (!adminLink) {
        adminLink = document.createElement("a");
        adminLink.href = "admin.html";
        adminLink.textContent = "Admin";
        mainNav.insertBefore(adminLink, logoutButton);
      }
      adminLink.hidden = false;
    } else if (adminLink) {
      adminLink.hidden = true;
    }
    
    return;
  }

  if (loginLink) {
    loginLink.hidden = false;
  }

  if (registerLink) {
    registerLink.hidden = false;
  }

  if (logoutButton) {
    logoutButton.hidden = true;
  }

  if (adminLink) {
    adminLink.hidden = true;
  }
};
```

#### Modificarea C: Eliminați vulnerabilitățile `.innerHTML` (înlocuiți porțiunile respective în `main.js`)

**1. În interiorul `loadEventsList` (înlocuiți zona cu `No events yet`):**
*Cod vechi:*
```javascript
    if (!payload.events.length) {
      const empty = document.createElement("article");
      empty.className = "event-card";
      empty.innerHTML = '<div class="card-body"><h3>No events yet</h3><p>Be the first person to add one.</p></div>';
      eventsRoot.append(empty);
      updateFeaturedEvent(null);
      return;
    }
```
*Cod nou de înlocuit:*
```javascript
    if (!payload.events.length) {
      const empty = document.createElement("article");
      empty.className = "event-card";
      const cardBody = document.createElement("div");
      cardBody.className = "card-body";
      const h3 = document.createElement("h3");
      h3.textContent = "No events yet";
      const p = document.createElement("p");
      p.textContent = "Be the first person to add one.";
      cardBody.append(h3, p);
      empty.append(cardBody);
      eventsRoot.append(empty);
      updateFeaturedEvent(null);
      return;
    }
```

**2. În interiorul blocului catch al `loadEventsList`:**
*Cod vechi:*
```javascript
  } catch (error) {
    eventsRoot.innerHTML = '<article class="event-card"><div class="card-body"><h3>Events could not be loaded</h3><p>Try refreshing the page.</p></div></article>';
  }
```
*Cod nou de înlocuit:*
```javascript
  } catch (error) {
    eventsRoot.replaceChildren();
    const article = document.createElement("article");
    article.className = "event-card";
    const cardBody = document.createElement("div");
    cardBody.className = "card-body";
    const h3 = document.createElement("h3");
    h3.textContent = "Events could not be loaded";
    const p = document.createElement("p");
    p.textContent = "Try refreshing the page.";
    cardBody.append(h3, p);
    article.append(cardBody);
    eventsRoot.append(article);
  }
```

**3. În interiorul `initializeCreateEventForm` (pentru locations select):**
*Cod vechi:*
```javascript
      locationSelect.innerHTML = '<option value="" disabled selected>Select a location...</option>';
      locationsList.forEach(loc => {
        const option = document.createElement("option");
        option.value = loc.name;
        option.textContent = `${loc.name} (${loc.area})`;
        locationSelect.append(option);
      });
    } catch (err) {
      console.error("Failed to load locations for form:", err);
      locationSelect.innerHTML = '<option value="" disabled selected>Failed to load locations</option>';
    }
```
*Cod nou de înlocuit:*
```javascript
      locationSelect.replaceChildren();
      const defaultOpt = document.createElement("option");
      defaultOpt.value = "";
      defaultOpt.disabled = true;
      defaultOpt.selected = true;
      defaultOpt.textContent = "Select a location...";
      locationSelect.append(defaultOpt);

      locationsList.forEach(loc => {
        const option = document.createElement("option");
        option.value = loc.name;
        option.textContent = `${loc.name} (${loc.area})`;
        locationSelect.append(option);
      });
    } catch (err) {
      console.error("Failed to load locations for form:", err);
      locationSelect.replaceChildren();
      const errorOpt = document.createElement("option");
      errorOpt.value = "";
      errorOpt.disabled = true;
      errorOpt.selected = true;
      errorOpt.textContent = "Failed to load locations";
      locationSelect.append(errorOpt);
    }
```

**4. În interiorul `initializeCreateEventForm` (pentru sports select change):**
*Cod vechi:*
```javascript
        sportSelect.disabled = false;
        sportSelect.innerHTML = '<option value="" disabled selected>Select a sport...</option>';

        const sports = selectedLoc.sports ? selectedLoc.sports.split(", ") : [];
        sports.forEach(sport => {
          const option = document.createElement("option");
          option.value = sport;
          option.textContent = sport;
          sportSelect.append(option);
        });
      } else {

        sportSelect.disabled = true;
        sportSelect.innerHTML = '<option value="" disabled selected>First select the location...</option>';
```
*Cod nou de înlocuit:*
```javascript
        sportSelect.disabled = false;
        sportSelect.replaceChildren();
        const defSportOpt = document.createElement("option");
        defSportOpt.value = "";
        defSportOpt.disabled = true;
        defSportOpt.selected = true;
        defSportOpt.textContent = "Select a sport...";
        sportSelect.append(defSportOpt);

        const sports = selectedLoc.sports ? selectedLoc.sports.split(", ") : [];
        sports.forEach(sport => {
          const option = document.createElement("option");
          option.value = sport;
          option.textContent = sport;
          sportSelect.append(option);
        });
      } else {

        sportSelect.disabled = true;
        sportSelect.replaceChildren();
        const fallbackOpt = document.createElement("option");
        fallbackOpt.value = "";
        fallbackOpt.disabled = true;
        fallbackOpt.selected = true;
        fallbackOpt.textContent = "First select the location...";
        sportSelect.append(fallbackOpt);
```

**5. În interiorul `initializeCreateEventForm` la submit succes:**
*Cod vechi:*
```javascript
      form.reset();
      if (sportSelect) {
        sportSelect.disabled = true;
        sportSelect.innerHTML = '<option value="" disabled selected>First select the location...</option>';
      }
```
*Cod nou de înlocuit:*
```javascript
      form.reset();
      if (sportSelect) {
        sportSelect.disabled = true;
        sportSelect.replaceChildren();
        const resetOpt = document.createElement("option");
        resetOpt.value = "";
        resetOpt.disabled = true;
        resetOpt.selected = true;
        resetOpt.textContent = "First select the location...";
        sportSelect.append(resetOpt);
      }
```

**6. În interiorul `renderEventParticipants` (pentru participant list):**
*Cod vechi:*
```javascript
    item.innerHTML = "<span>No participants yet</span>";
```
*Cod nou de înlocuit:*
```javascript
    const noPart = document.createElement("span");
    noPart.textContent = "No participants yet";
    item.replaceChildren(noPart);
```
