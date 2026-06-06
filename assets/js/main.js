const navToggle = document.querySelector(".nav-toggle");
const mainNav = document.querySelector(".main-nav");

if (navToggle && mainNav) {
  navToggle.addEventListener("click", () => {
    const isOpen = mainNav.classList.toggle("open");
    navToggle.setAttribute("aria-expanded", String(isOpen));
  });
}

document.querySelectorAll(".filter-chips").forEach((chipGroup) => {
  chipGroup.addEventListener("click", (event) => {
    const chip = event.target.closest(".chip");

    if (!chip) {
      return;
    }

    chipGroup.querySelectorAll(".chip").forEach((item) => {
      item.classList.remove("active");
    });

    chip.classList.add("active");
  });
});

const authState = {
  csrfToken: null,
  token: localStorage.getItem("logAuthToken"),
  user: null,
  loaded: false,
  loading: null
};

const apiRequest = async (url, options = {}) => {
  const headers = {
    Accept: "application/json",
    ...(options.headers || {})
  };

  if (authState.token) {
    headers.Authorization = `Bearer ${authState.token}`;
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

const getCsrfToken = async () => {
  if (authState.csrfToken) {
    return authState.csrfToken;
  }

  const payload = await apiRequest("backend/public/csrf.php");
  authState.csrfToken = payload.token;

  return authState.csrfToken;
};

const setFormMessage = (form, message, type = "success") => {
  const messageElement = form.querySelector("[data-auth-message]");

  if (!messageElement) {
    return;
  }

  messageElement.textContent = message;
  messageElement.classList.add("visible");
  messageElement.classList.toggle("error", type === "error");
};

const setFormErrors = (form, errors = {}) => {
  form.querySelectorAll("[name]").forEach((field) => {
    const error = errors[field.name];

    field.removeAttribute("aria-invalid");
    field.removeAttribute("title");

    if (error) {
      field.setAttribute("aria-invalid", "true");
      field.setAttribute("title", error);
    }
  });
};

document.querySelectorAll("[data-token-from-url]").forEach((field) => {
  const params = new URLSearchParams(window.location.search);
  const tokenName = field.dataset.tokenFromUrl || "token";

  field.value = params.get(tokenName) || "";
});

const createLogoutButton = () => {
  const button = document.createElement("button");

  button.className = "nav-logout";
  button.type = "button";
  button.textContent = "Logout";

  button.addEventListener("click", async () => {
    button.disabled = true;

    try {
      const csrfToken = await getCsrfToken();

      await apiRequest("backend/public/logout.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify({})
      });

      authState.csrfToken = null;
      authState.token = null;
      authState.user = null;
      authState.loaded = true;
      localStorage.removeItem("logAuthToken");
      window.location.assign("index.html");
    } catch (error) {
      button.disabled = false;
    }
  });

  return button;
};

const updateAuthNavigation = async () => {
  if (!mainNav) {
    return;
  }

  const user = await loadCurrentUser();
  const loginLink = mainNav.querySelector('a[href="login.html"]');
  const registerLink = mainNav.querySelector('a[href="register.html"]');
  const profileLink = mainNav.querySelector('a[href="profile.html"]');
  let logoutButton = mainNav.querySelector(".nav-logout");

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
};

document.querySelectorAll("[data-auth-form]").forEach((form) => {
  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    const submitButton = form.querySelector("[type='submit']");
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());

    setFormErrors(form);
    setFormMessage(form, "Checking your details...");

    if (submitButton) {
      submitButton.disabled = true;
    }

    try {
      const csrfToken = await getCsrfToken();

      const result = await apiRequest(form.dataset.authEndpoint, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify(payload)
      });

      authState.user = result.user || null;

      if (result.token) {
        authState.token = result.token;
        localStorage.setItem("logAuthToken", result.token);
      }

      setFormMessage(form, result.message || "Done.");

      if (form.dataset.authRedirect) {
        window.location.assign(form.dataset.authRedirect);
      }
    } catch (error) {
      const errorPayload = error.payload || {};

      setFormErrors(form, errorPayload.errors);
      setFormMessage(form, errorPayload.message || error.message, "error");
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
      }
    }
  });
});

document.querySelectorAll("[data-auth-auto-submit]").forEach((form) => {
  if (typeof form.requestSubmit === "function") {
    form.requestSubmit();
    return;
  }

  form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
});

const loadCurrentUser = async () => {
  if (authState.loaded) {
    return authState.user;
  }

  if (authState.loading) {
    return authState.loading;
  }

  authState.loading = (async () => {
    try {
      const payload = await apiRequest("backend/public/me.php");
      authState.user = payload.authenticated ? payload.user : null;
    } catch (error) {
      authState.user = null;
    } finally {
      authState.loaded = true;
      authState.loading = null;
    }

    return authState.user;
  })();

  return authState.loading;
};

const updateProfilePage = async () => {
  const profileName = document.querySelector("[data-profile-name]");
  const profileEmail = document.querySelector("[data-profile-email]");
  const requiresAuth = document.body.dataset.requiresAuth !== undefined;

  if (!profileName && !requiresAuth) {
    return;
  }

  const user = await loadCurrentUser();

  if (!user && requiresAuth) {
    window.location.assign("login.html");
    return;
  }

  if (user && profileName) {
    profileName.textContent = user.username;
    document.title = `${user.username} | Local Greetings`;
  }

  if (user && profileEmail) {
    profileEmail.textContent = user.email;
  }
};

const getTopSport = (stats) => {
  if (!stats.sportMix.length) {
    return null;
  }

  return [...stats.sportMix].sort((first, second) => second.percent - first.percent)[0];
};

const getTopZone = (stats) => {
  if (!stats.zones.length) {
    return null;
  }

  return [...stats.zones].sort((first, second) => second.percent - first.percent)[0];
};

const getActivitySummary = (stats) => {
  const topSport = getTopSport(stats);
  const topZone = getTopZone(stats);

  if (!topSport || !topZone || stats.gamesPlayed === 0) {
    return "No completed games yet. Join an event and this section will update from your activity.";
  }

  const secondarySports = stats.sportMix
    .filter((sport) => sport !== topSport)
    .slice(0, 2)
    .map((sport) => sport.label.toLowerCase())
    .join(" and ");

  const varietyText = secondarySports
    ? `, with ${secondarySports} adding variety`
    : "";

  return `${stats.gamesPlayed} games played so far, mostly ${topSport.label.toLowerCase()} around ${topZone.label}. The usual start time is ${stats.favoriteHour}${varietyText}.`;
};

const appendListItem = (root, labelText, valueText) => {
  const item = document.createElement("li");
  const label = document.createElement("span");
  const value = document.createElement("strong");

  label.textContent = labelText;
  value.textContent = valueText;
  item.append(label, value);
  root.append(item);
};

const renderProfileStats = (profileStats) => {
  document.querySelectorAll("[data-profile-stat]").forEach((element) => {
    const statName = element.dataset.profileStat;

    if (Object.prototype.hasOwnProperty.call(profileStats, statName)) {
      element.textContent = profileStats[statName];
    }
  });

  const sportMixRoot = document.querySelector("[data-sport-mix]");

  if (sportMixRoot) {
    sportMixRoot.replaceChildren();

    if (!profileStats.sportMix.length) {
      const empty = document.createElement("p");
      empty.className = "profile-note";
      empty.textContent = "No sport history yet.";
      sportMixRoot.append(empty);
    }

    profileStats.sportMix.forEach((sport) => {
      const item = document.createElement("div");
      const topLine = document.createElement("div");
      const label = document.createElement("span");
      const value = document.createElement("strong");
      const track = document.createElement("span");
      const bar = document.createElement("span");

      item.className = "stat-bar";
      topLine.className = "stat-bar-top";
      track.className = "stat-bar-track";
      bar.className = "stat-bar-fill";
      bar.style.width = `${sport.percent}%`;

      label.textContent = sport.label;
      value.textContent = `${sport.percent}%`;
      topLine.append(label, value);
      track.append(bar);
      item.append(topLine, track);
      sportMixRoot.append(item);
    });
  }

  const zoneStatsRoot = document.querySelector("[data-zone-stats]");

  if (zoneStatsRoot) {
    zoneStatsRoot.replaceChildren();

    if (!profileStats.zones.length) {
      appendListItem(zoneStatsRoot, "No zones yet", "0%");
    }

    profileStats.zones.forEach((zone) => {
      const item = document.createElement("li");
      const label = document.createElement("span");
      const value = document.createElement("strong");

      label.textContent = zone.label;
      value.textContent = `${zone.percent}%`;
      item.append(label, value);
      zoneStatsRoot.append(item);
    });
  }

  const preferencesRoot = document.querySelector("[data-preferences]");

  if (preferencesRoot) {
    preferencesRoot.replaceChildren();
    appendListItem(preferencesRoot, "Favorite sport", profileStats.preferences.favoriteSport);
    appendListItem(preferencesRoot, "Favorite area", profileStats.preferences.favoriteArea);
    appendListItem(preferencesRoot, "Level", profileStats.preferences.level);
  }

  const upcomingRoot = document.querySelector("[data-upcoming-events]");

  if (upcomingRoot) {
    upcomingRoot.replaceChildren();

    if (!profileStats.upcomingEvents.length) {
      appendListItem(upcomingRoot, "No upcoming events", "");
    }

    profileStats.upcomingEvents.forEach((eventItem) => {
      appendListItem(upcomingRoot, eventItem.title, eventItem.dateLabel);
    });
  }

  const summary = document.querySelector("[data-profile-summary]");

  if (summary) {
    summary.textContent = getActivitySummary(profileStats);
  }
};

const loadProfileStats = async () => {
  if (!document.querySelector("[data-profile-stat]")) {
    return;
  }

  try {
    const payload = await apiRequest("backend/public/profile-stats.php");
    renderProfileStats(payload.stats);
  } catch (error) {
    const summary = document.querySelector("[data-profile-summary]");

    if (summary) {
      summary.textContent = "Profile statistics could not be loaded right now.";
    }
  }
};

updateProfilePage();
updateAuthNavigation();
loadProfileStats();

const eventDetails = {
  "copou-football": {
    title: "Quick match in Copou",
    sport: "Football",
    date: "Today",
    time: "18:30",
    spots: "3 left",
    location: "Copou Arena",
    area: "Copou",
    level: "Mixed level",
    organizer: "Local Greetings Team",
    badge: "Football",
    description: "Artificial turf, 5 vs 5, friendly pace, and open registration for players who want a quick match after work.",
    plan: [
      ["18:15", "Arrival and teams"],
      ["18:30", "Warm-up"],
      ["18:45", "Match starts"],
      ["20:00", "Wrap-up"]
    ]
  },
  "palas-basketball": {
    title: "Free play at Palas",
    sport: "Basketball",
    date: "Tomorrow",
    time: "19:00",
    spots: "5 left",
    location: "Palas Sport Court",
    area: "Palas",
    level: "Beginner friendly",
    organizer: "Andrei M.",
    badge: "Basketball",
    description: "Public basketball session for people who want to play after work, rotate teams, and meet new players.",
    plan: [
      ["18:50", "Meet near the court"],
      ["19:00", "Warm-up shots"],
      ["19:15", "Rotating teams"],
      ["20:30", "Cool down"]
    ]
  },
  "ciric-tennis": {
    title: "Doubles at Ciric Base",
    sport: "Tennis",
    date: "Saturday",
    time: "10:00",
    spots: "2 left",
    location: "Ciric Base",
    area: "Ciric",
    level: "Intermediate",
    organizer: "Mara P.",
    badge: "Tennis",
    description: "Two reserved courts for doubles, with registration open for players who already know the basics.",
    plan: [
      ["09:45", "Court check-in"],
      ["10:00", "Warm-up"],
      ["10:15", "Doubles games"],
      ["12:00", "Court release"]
    ]
  }
};

const detailRoot = document.querySelector("#event-detail");

if (detailRoot) {
  const params = new URLSearchParams(window.location.search);
  const eventId = params.get("id") || "copou-football";
  const eventItem = eventDetails[eventId];

  const setText = (selector, value) => {
    const element = document.querySelector(selector);

    if (element) {
      element.textContent = value;
    }
  };

  if (eventItem) {
    document.title = `${eventItem.title} | Local Greetings`;

    setText("#detail-sport", eventItem.sport);
    setText("#detail-title", eventItem.title);
    setText("#detail-description", eventItem.description);
    setText("#detail-date", eventItem.date);
    setText("#detail-time", eventItem.time);
    setText("#detail-spots", eventItem.spots);
    setText("#detail-badge", eventItem.badge);
    setText("#detail-location", `${eventItem.location}, ${eventItem.area}`);
    setText("#detail-place", eventItem.location);
    setText("#detail-area", eventItem.area);
    setText("#detail-level", eventItem.level);
    setText("#detail-organizer", eventItem.organizer);

    const planList = document.querySelector("#detail-plan");

    if (planList) {
      planList.replaceChildren();

      eventItem.plan.forEach(([time, label]) => {
        const item = document.createElement("li");
        const timeElement = document.createElement("span");
        const labelElement = document.createElement("strong");

        timeElement.textContent = time;
        labelElement.textContent = label;

        item.append(timeElement, labelElement);
        planList.append(item);
      });
    }
  } else {
    setText("#detail-title", "Event not found");
    setText("#detail-description", "This event is no longer available. Go back to the events list and choose another one.");
    detailRoot.classList.add("event-missing");
  }

  const signupForm = document.querySelector("#signup-form");
  const signupMessage = document.querySelector("#signup-message");

  if (signupForm && signupMessage) {
    signupForm.addEventListener("submit", (event) => {
      event.preventDefault();

      const formData = new FormData(signupForm);
      const registrations = JSON.parse(localStorage.getItem("eventRegistrations") || "[]");

      registrations.push({
        eventId,
        name: formData.get("name"),
        email: formData.get("email"),
        message: formData.get("message"),
        createdAt: new Date().toISOString()
      });

      localStorage.setItem("eventRegistrations", JSON.stringify(registrations));
      signupForm.reset();

      signupMessage.textContent = "Registration sent. The organizer will contact you soon.";
      signupMessage.classList.add("visible");
    });
  }
}
