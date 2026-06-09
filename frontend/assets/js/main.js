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
  if (url.startsWith("backend/public/")) {
    url = "../" + url;
  }

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

    // Remove old error message span if it exists
    const oldErrorSpan = field.parentNode.querySelector(".field-error-msg");
    if (oldErrorSpan) {
      oldErrorSpan.remove();
    }

    if (error) {
      field.setAttribute("aria-invalid", "true");
      field.setAttribute("title", error);

      const errorSpan = document.createElement("span");
      errorSpan.className = "field-error-msg";
      errorSpan.style.color = "var(--color-danger)";
      errorSpan.style.fontSize = "0.8rem";
      errorSpan.style.display = "block";
      errorSpan.style.marginTop = "0.25rem";
      errorSpan.textContent = error;
      field.parentNode.appendChild(errorSpan);
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
      await apiRequest("backend/public/logout.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
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
      const result = await apiRequest(form.dataset.authEndpoint, {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
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

  const subscriptionsRoot = document.querySelector("[data-subscriptions]");

  if (subscriptionsRoot) {
    subscriptionsRoot.replaceChildren();

    if (!profileStats.preferences.subscriptions || !profileStats.preferences.subscriptions.length) {
      appendListItem(subscriptionsRoot, "No subscriptions yet", "");
    } else {
      profileStats.preferences.subscriptions.forEach((sub) => {
        appendListItem(subscriptionsRoot, sub.name, sub.area);
      });
    }
  }

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

const parseEventDate = (eventDate) => {
  if (!eventDate) {
    return null;
  }

  const parsedDate = new Date(String(eventDate).replace(" ", "T"));

  return Number.isNaN(parsedDate.getTime()) ? null : parsedDate;
};

const eventDateLabel = (eventDate) => {
  const parsedDate = parseEventDate(eventDate);

  if (!parsedDate) {
    return "Date pending";
  }

  const dd = String(parsedDate.getDate()).padStart(2, '0');
  const mm = String(parsedDate.getMonth() + 1).padStart(2, '0');
  const yyyy = parsedDate.getFullYear();
  return `${dd}/${mm}/${yyyy}`;
};

const eventTimeLabel = (eventDate) => {
  const parsedDate = parseEventDate(eventDate);

  if (!parsedDate) {
    return "-";
  }

  return new Intl.DateTimeFormat("en", {
    hour: "2-digit",
    minute: "2-digit"
  }).format(parsedDate);
};

const eventSpotsLabel = (eventItem) => {
  if (eventItem.spotsLeft === null || eventItem.spotsLeft === undefined) {
    return "Open spots";
  }

  if (eventItem.spotsLeft <= 0) {
    return "Full";
  }

  return eventItem.spotsLeft === 1 ? "1 spot left" : `${eventItem.spotsLeft} spots left`;
};

const sportClassName = (sport) => {
  const normalizedSport = String(sport || "").toLowerCase();

  if (normalizedSport.includes("basket")) {
    return "basketball";
  }

  if (normalizedSport.includes("tennis")) {
    return "tennis";
  }

  return "football";
};

const setPageText = (selector, value) => {
  const element = document.querySelector(selector);

  if (element) {
    element.textContent = value;
  }
};

const renderEventCard = (eventItem) => {
  const article = document.createElement("article");
  const media = document.createElement("div");
  const body = document.createElement("div");
  const topline = document.createElement("div");
  const sport = document.createElement("span");
  const time = document.createElement("strong");
  const title = document.createElement("h3");
  const description = document.createElement("p");
  const footer = document.createElement("div");
  const location = document.createElement("span");
  const details = document.createElement("a");

  article.className = "event-card";
  media.className = `card-media ${sportClassName(eventItem.sport)}`;
  body.className = "card-body";
  topline.className = "card-topline";
  footer.className = "card-footer";

  sport.textContent = eventItem.sport;
  time.textContent = eventTimeLabel(eventItem.eventDate);
  title.textContent = eventItem.title;
  description.textContent = `${eventItem.description} ${eventSpotsLabel(eventItem)}.`;
  location.textContent = eventItem.location.name;
  details.href = `event-details.html?id=${eventItem.id}`;
  details.textContent = "Details";

  topline.append(sport, time);
  footer.append(location, details);
  body.append(topline, title, description, footer);
  article.append(media, body);

  return article;
};

const updateFeaturedEvent = (eventItem) => {
  const featuredRoot = document.querySelector("[data-featured-event]");

  if (!featuredRoot) {
    return;
  }

  if (!eventItem) {
    setPageText("[data-featured-sport]", "Event");
    setPageText("[data-featured-date]", "No events");
    setPageText("[data-featured-title]", "No events yet");
    setPageText("[data-featured-summary]", "Add the first event and it will appear here.");
    setPageText("[data-featured-area]", "Iasi");
    setPageText("[data-featured-participants]", "0 participants");
    return;
  }

  const detailsLink = document.querySelector("[data-featured-link]");

  setPageText("[data-featured-sport]", eventItem.sport);
  setPageText("[data-featured-date]", `${eventDateLabel(eventItem.eventDate)}, ${eventTimeLabel(eventItem.eventDate)}`);
  setPageText("[data-featured-title]", eventItem.title);
  setPageText("[data-featured-summary]", `${eventSpotsLabel(eventItem)}. ${eventItem.skillLevel}.`);
  setPageText("[data-featured-area]", eventItem.location.area);
  setPageText("[data-featured-participants]", `${eventItem.participantCount} participants`);

  if (detailsLink) {
    detailsLink.href = `event-details.html?id=${eventItem.id}`;
  }
};

const loadEventsList = async () => {
  const eventsRoot = document.querySelector("[data-events-list]");

  if (!eventsRoot) {
    return;
  }

  try {
    const payload = await apiRequest("backend/public/events.php");
    eventsRoot.replaceChildren();

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

    updateFeaturedEvent(payload.events[0]);

    payload.events.forEach((eventItem) => {
      eventsRoot.append(renderEventCard(eventItem));
    });
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
};

const setEventFormMessage = (form, message, type = "success") => {
  const messageElement = form.querySelector("[data-event-message]");

  if (!messageElement) {
    return;
  }

  messageElement.textContent = message;
  messageElement.classList.add("visible");
  messageElement.classList.toggle("error", type === "error");
};

const initializeCreateEventForm = async () => {
  const form = document.querySelector("[data-event-form]");
  const guestPanel = document.querySelector("[data-event-guest]");

  if (!form) {
    return;
  }

  const user = await loadCurrentUser();

  if (!user) {
    form.hidden = true;

    if (guestPanel) {
      guestPanel.hidden = false;
    }

    return;
  }

  form.hidden = false;

  const dateInput = form.querySelector('input[name="event_date"]');
  const endDateInput = form.querySelector('input[name="end_date"]');
  
  if (dateInput) {
    const now = new Date();
    const tzOffset = now.getTimezoneOffset() * 60000;
    const localISOTime = new Date(now - tzOffset).toISOString().slice(0, 16);
    dateInput.min = localISOTime;
    if (endDateInput) {
      endDateInput.min = localISOTime;
      
      dateInput.addEventListener('change', () => {
        if (dateInput.value) {
          endDateInput.min = dateInput.value;
          if (endDateInput.value && endDateInput.value < dateInput.value) {
            endDateInput.value = dateInput.value;
          }
        }
      });
    }
  }

  const locationSelect = form.querySelector('select[name="location"]');
  const sportSelect = form.querySelector('select[name="sport"]');
  const areaSelect = form.querySelector('[name="area"]');
  let locationsList = [];

  const selectedLocation = () => {
    const selectedOption = locationSelect.options[locationSelect.selectedIndex];
    const selectedId = selectedOption ? selectedOption.dataset.locationId : null;

    return locationsList.find(loc => String(loc.id) === selectedId) ||
      locationsList.find(loc => loc.name === locationSelect.value);
  };

  const ensureAreaOption = (area) => {
    if (!areaSelect || !area || !areaSelect.options) {
      return;
    }

    const existingOption = Array.from(areaSelect.options).find(option => option.value === area);

    if (existingOption) {
      return;
    }

    const option = document.createElement("option");
    option.value = area;
    option.textContent = area;
    areaSelect.append(option);
  };

  const setAreaFromLocation = (location) => {
    if (!areaSelect) {
      return;
    }

    if (!location) {
      areaSelect.value = "";
      return;
    }

    ensureAreaOption(location.area);
    areaSelect.value = location.area;
  };

  if (locationSelect && sportSelect) {
    try {
      const locationsPayload = await apiRequest("backend/public/locations.php");
      locationsList = locationsPayload.locations || [];

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
        option.dataset.locationId = String(loc.id);
        option.textContent = `${loc.name} (${loc.area})`;
        locationSelect.append(option);
        ensureAreaOption(loc.area);
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

    locationSelect.addEventListener("change", () => {
      const selectedLoc = selectedLocation();

      if (selectedLoc) {
        setAreaFromLocation(selectedLoc);

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

        setAreaFromLocation(null);
      }
    });
  }

  if (guestPanel) {
    guestPanel.hidden = true;
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    const submitButton = form.querySelector("[type='submit']");
    const payload = Object.fromEntries(new FormData(form).entries());
    const currentLocation = locationSelect ? selectedLocation() : null;
    const selectedLevels = Array.from(form.querySelectorAll('input[name="skill_levels"]:checked')).map(checkbox => checkbox.value);

    if (currentLocation) {
      payload.location = currentLocation.name;
      payload.area = currentLocation.area;
    }

    let skillLevelString = "";
    if (selectedLevels.length === 3) {
      skillLevelString = "All levels";
    } else if (selectedLevels.length > 0) {
      skillLevelString = selectedLevels.join(", ");
    } else {
      skillLevelString = "";
    }
    payload.skill_level = skillLevelString;
    delete payload.skill_levels;


    setFormErrors(form);
    setEventFormMessage(form, "Saving event...");

    if (submitButton) {
      submitButton.disabled = true;
    }

    try {
      const result = await apiRequest("backend/public/events.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify(payload)
      });

      form.reset();
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
      setEventFormMessage(form, result.message || "Event created.");
      await loadEventsList();

    } catch (error) {
      const errorPayload = error.payload || {};

      if (errorPayload.message === "Authentication required.") {
        window.location.assign("login.html");
        return;
      }

      setFormErrors(form, errorPayload.errors);
      setEventFormMessage(form, errorPayload.message || error.message, "error");
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
      }
    }
  });
};

const renderEventPlan = (eventItem) => {
  const planList = document.querySelector("#detail-plan");

  if (!planList) {
    return;
  }

  planList.replaceChildren();

  const startTime = eventTimeLabel(eventItem.eventDate);
  const endTime = eventItem.endDate ? eventTimeLabel(eventItem.endDate) : null;
  const meetTime = endTime ? `${startTime} - ${endTime}` : startTime;

  [
    ["Meet", `${meetTime} at ${eventItem.location.name}`],
    ["Sport", eventItem.sport],
    ["Group", `${eventItem.participantCount}/${eventItem.maxParticipants || "open"} registered`],
    ["Status", eventItem.isFull ? "Full" : "Registration open"]
  ].forEach(([label, value]) => {
    const item = document.createElement("li");
    const labelElement = document.createElement("span");
    const valueElement = document.createElement("strong");

    labelElement.textContent = label;
    valueElement.textContent = value;
    item.append(labelElement, valueElement);
    planList.append(item);
  });
};

const renderParticipantsList = (participants) => {
  const participantsRoot = document.querySelector("#detail-participants");
  const panel = document.querySelector("#participants-panel");

  if (!participantsRoot || !panel) {
    return;
  }

  participantsRoot.replaceChildren();

  if (!participants.length) {
    const item = document.createElement("li");
    const noPart = document.createElement("span");

    noPart.textContent = "No participants yet";
    item.replaceChildren(noPart);
    participantsRoot.append(item);

  } else {
    participants.forEach((username, index) => {
      const item = document.createElement("li");
      const numElement = document.createElement("span");
      const nameElement = document.createElement("strong");

      numElement.textContent = `#${index + 1}`;
      nameElement.textContent = username;
      item.append(numElement, nameElement);
      participantsRoot.append(item);
    });
  }

  panel.hidden = false;
};

const updateJoinControls = async (eventItem) => {
  const joinButton = document.querySelector("#join-event-button");
  const loginLink = document.querySelector("#login-before-join");
  const signupMessage = document.querySelector("#signup-message");

  if (!joinButton || !loginLink || !signupMessage) {
    return;
  }

  const user = await loadCurrentUser();

  if (!user) {
    joinButton.hidden = true;
    loginLink.hidden = false;
    signupMessage.textContent = "You need to be logged in before registering.";
    signupMessage.classList.add("visible");
    return;
  }

  loginLink.hidden = true;
  joinButton.hidden = false;
  joinButton.disabled = eventItem.userJoined || eventItem.isFull;
  joinButton.textContent = eventItem.userJoined ? "Already registered" : "Register for event";

  await updateOrganizerControls(eventItem, user);

  if (eventItem.isFull && !eventItem.userJoined) {
    signupMessage.textContent = "This event is already full.";
    signupMessage.classList.add("visible");
  }

  joinButton.addEventListener("click", async () => {
    joinButton.disabled = true;
    signupMessage.textContent = "Registering...";
    signupMessage.classList.add("visible");
    signupMessage.classList.remove("error");

    try {
      const result = await apiRequest("backend/public/event-join.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({ event_id: eventItem.id })
      });

      signupMessage.textContent = result.message || "You are registered for this event.";
      updateEventDetails(result.event);
      await updateJoinControls(result.event);
    } catch (error) {
      const errorPayload = error.payload || {};

      if (errorPayload.message === "Authentication required.") {
        window.location.assign("login.html");
        return;
      }

      signupMessage.textContent = errorPayload.message || error.message;
      signupMessage.classList.add("error");
      joinButton.disabled = false;
    }
  }, { once: true });
};

const updateOrganizerControls = async (eventItem, user) => {
  const deleteButton = document.querySelector("#delete-event-button");
  if (!deleteButton) {
    return;
  }

  const isOrganizer = user && Number(user.id) === Number(eventItem.organizerId);

  if (!isOrganizer) {
    deleteButton.hidden = true;
    return;
  }

  deleteButton.hidden = false;

  const newDeleteButton = deleteButton.cloneNode(true);
  deleteButton.parentNode.replaceChild(newDeleteButton, deleteButton);

  newDeleteButton.addEventListener("click", async () => {
    if (!confirm("Are you sure you want to delete this event? This action cannot be undone.")) {
      return;
    }

    newDeleteButton.disabled = true;
    const signupMessage = document.querySelector("#signup-message");
    if (signupMessage) {
      signupMessage.textContent = "Deleting event...";
      signupMessage.classList.add("visible");
      signupMessage.classList.remove("error");
    }

    try {
      await apiRequest(`backend/public/event.php?id=${encodeURIComponent(eventItem.id)}`, {
        method: "DELETE",
        headers: {
          "Content-Type": "application/json"
        }
      });

      if (signupMessage) {
        signupMessage.textContent = "Event deleted successfully. Redirecting...";
      }
      setTimeout(() => {
        window.location.assign("index.html");
      }, 1500);
    } catch (error) {
      const errorPayload = error.payload || {};
      if (signupMessage) {
        signupMessage.textContent = errorPayload.message || error.message;
        signupMessage.classList.add("error");
      }
      newDeleteButton.disabled = false;
    }
  });
};

const updateEventDetails = (eventItem) => {
  document.title = `${eventItem.title} | Local Greetings`;

  setPageText("#detail-sport", eventItem.sport);
  setPageText("#detail-title", eventItem.title);
  setPageText("#detail-description", eventItem.description);
  const startDate = eventDateLabel(eventItem.eventDate);
  const endDate = eventItem.endDate ? eventDateLabel(eventItem.endDate) : null;
  const dateText = (endDate && endDate !== startDate) ? `${startDate} to ${endDate}` : startDate;
  
  const startTime = eventTimeLabel(eventItem.eventDate);
  const endTime = eventItem.endDate ? eventTimeLabel(eventItem.endDate) : null;
  const timeText = endTime ? `${startTime} - ${endTime}` : startTime;

  setPageText("#detail-date", dateText);
  setPageText("#detail-time", timeText);
  setPageText("#detail-spots", eventSpotsLabel(eventItem));
  setPageText("#detail-badge", eventItem.sport);
  setPageText("#detail-location", `${eventItem.location.name}, ${eventItem.location.area}`);
  setPageText("#detail-place", eventItem.location.name);
  setPageText("#detail-area", eventItem.location.area);
  setPageText("#detail-level", eventItem.skillLevel);
  setPageText("#detail-organizer", eventItem.organizer);
  renderEventPlan(eventItem);
  renderParticipantsList(eventItem.participants || []);
};

const loadEventDetails = async () => {
  const detailRoot = document.querySelector("#event-detail");

  if (!detailRoot) {
    return;
  }

  const params = new URLSearchParams(window.location.search);
  const eventId = params.get("id");

  if (!eventId) {
    setPageText("#detail-title", "Event not found");
    setPageText("#detail-description", "Go back to the events list and choose an event.");
    detailRoot.classList.add("event-missing");
    return;
  }

  try {
    const payload = await apiRequest(`backend/public/event.php?id=${encodeURIComponent(eventId)}`);
    updateEventDetails(payload.event);
    await updateJoinControls(payload.event);
  } catch (error) {
    setPageText("#detail-title", "Event not found");
    setPageText("#detail-description", "This event is no longer available. Go back to the events list and choose another one.");
    detailRoot.classList.add("event-missing");
  }
};
const appendLocationHeading = (root, eyebrowText, titleText, action = null) => {
  const heading = document.createElement("div");
  const textWrap = document.createElement("div");
  const eyebrow = document.createElement("p");
  const title = document.createElement("h2");

  heading.className = "section-heading compact";
  eyebrow.className = "eyebrow";
  eyebrow.textContent = eyebrowText;
  title.textContent = titleText;

  textWrap.append(eyebrow, title);
  heading.append(textWrap);

  if (action) {
    heading.append(action);
  }

  root.append(heading);
};

const buildLocationPopup = (location) => {
  const popup = document.createElement("div");
  const name = document.createElement("strong");
  const address = document.createElement("span");
  const area = document.createElement("span");

  popup.className = "map-popup";
  name.textContent = location.name;
  address.textContent = location.address || "Address pending";
  area.textContent = `Area: ${location.area || "Iasi"}`;

  popup.append(name, address, area);

  if (location.sports) {
    const sports = document.createElement("span");
    sports.textContent = `Sports: ${location.sports}`;
    popup.append(sports);
  }

  return popup;
};

const renderLocationEventItem = (eventItem) => {
  const article = document.createElement("article");
  const body = document.createElement("div");
  const title = document.createElement("h3");
  const meta = document.createElement("p");
  const link = document.createElement("a");

  article.className = "venue-event";
  title.textContent = eventItem.title;
  meta.textContent = `${eventItem.sport} · ${eventDateLabel(eventItem.eventDate)}, ${eventTimeLabel(eventItem.eventDate)} · ${eventSpotsLabel(eventItem)}`;
  link.className = "venue-event-link";
  link.href = `event-details.html?id=${encodeURIComponent(eventItem.id)}`;
  link.textContent = "Open";

  body.append(title, meta);
  article.append(body, link);

  return article;
};

// Map Initialization and Rendering using Leaflet & OpenStreetMap
const renderMapLocations = (map, locations) => {
  map.eachLayer((layer) => {
    if (layer instanceof L.Marker) {
      map.removeLayer(layer);
    }
  });
  const resultsRoot = document.querySelector(".results-panel");

  const renderLocationList = (markerByLocation) => {
    if (!resultsRoot) {
      return;
    }

    resultsRoot.replaceChildren();
    appendLocationHeading(resultsRoot, "Nearby", "Venues and events");

    if (!locations.length) {
      const empty = document.createElement("p");
      empty.className = "venue-item";
      empty.textContent = "No venues found. Try again later.";
      resultsRoot.append(empty);
      return;
    }

    locations.forEach((loc) => {
      const marker = markerByLocation.get(String(loc.id));
      const article = document.createElement("article");
      const body = document.createElement("div");
      const title = document.createElement("h3");
      const sports = document.createElement("p");
      const count = document.createElement("span");

      article.className = "venue-item";
      article.tabIndex = 0;
      article.setAttribute("role", "button");

      title.textContent = loc.name;
      sports.textContent = loc.sports || "No sports registered";
      count.textContent = `${loc.activeEventsCount || 0} events`;

      body.append(title, sports);
      article.append(body, count);

      const openLocation = () => {
        map.setView([loc.latitude, loc.longitude], 15);
        if (marker) {
          marker.openPopup();
        }
        renderLocationDetails(loc, marker, markerByLocation);
      };

      article.addEventListener("click", openLocation);
      article.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          openLocation();
        }
      });

      resultsRoot.append(article);
    });
  };

  const renderLocationDetails = async (location, marker, markerByLocation) => {
    if (!resultsRoot) {
      return;
    }

    resultsRoot.replaceChildren();

    const backButton = document.createElement("button");
    backButton.className = "icon-button";
    backButton.type = "button";
    backButton.setAttribute("aria-label", "Back to locations");
    backButton.textContent = "←";
    backButton.addEventListener("click", () => renderLocationList(markerByLocation));
    appendLocationHeading(resultsRoot, location.area || "Iasi", location.name, backButton);

    const summary = document.createElement("div");
    const sports = document.createElement("p");
    const address = document.createElement("p");
    const subscribeMessage = document.createElement("p");

    summary.className = "venue-summary";
    sports.textContent = location.sports || "No sports registered";
    address.textContent = location.address || "Address pending";
    subscribeMessage.className = "venue-subscribe-message";
    summary.append(sports, address);

    const actions = document.createElement("div");
    actions.className = "venue-actions";

    if (authState.token) {
      const subscribeButton = document.createElement("button");
      subscribeButton.className = "btn btn-secondary venue-subscribe";
      subscribeButton.type = "button";
      subscribeButton.textContent = location.subscribed ? "Unsubscribe" : "Subscribe";

      subscribeButton.addEventListener("click", async () => {
        subscribeButton.disabled = true;
        subscribeMessage.textContent = "";

        try {
          const method = location.subscribed ? "DELETE" : "POST";
          const payload = await apiRequest("backend/public/location-subscription.php", {
            method,
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ location_id: location.id })
          });

          location.subscribed = Boolean(payload.subscribed);
          subscribeButton.textContent = location.subscribed ? "Unsubscribe" : "Subscribe";
          subscribeMessage.textContent = payload.message;
        } catch (error) {
          subscribeMessage.textContent = error.message || "Subscription could not be changed.";
        } finally {
          subscribeButton.disabled = false;
        }
      });

      actions.append(subscribeButton);
    } else {
      const loginLink = document.createElement("a");
      loginLink.className = "btn btn-secondary venue-subscribe";
      loginLink.href = "login.html";
      loginLink.textContent = "Login to subscribe";
      actions.append(loginLink);
    }

    summary.append(actions, subscribeMessage);
    resultsRoot.append(summary);

    const list = document.createElement("div");
    list.className = "venue-events";
    const loading = document.createElement("p");
    loading.className = "venue-empty";
    loading.textContent = "Loading events...";
    list.append(loading);
    resultsRoot.append(list);

    if (marker) {
      marker.openPopup();
    }

    try {
      const payload = await apiRequest(`backend/public/events.php?location_id=${encodeURIComponent(location.id)}`);
      list.replaceChildren();

      if (!payload.events.length) {
        const empty = document.createElement("p");
        empty.className = "venue-empty";
        empty.textContent = "0 events at this location.";
        list.append(empty);
        return;
      }

      payload.events.forEach((eventItem) => {
        list.append(renderLocationEventItem(eventItem));
      });
    } catch (error) {
      list.replaceChildren();
      const empty = document.createElement("p");
      empty.className = "venue-empty";
      empty.textContent = "Events could not be loaded.";
      list.append(empty);
    }
  };

  const markerByLocation = new Map();

  locations.forEach((loc) => {
    if (!loc.latitude || !loc.longitude) {
      return;
    }

    const marker = L.marker([loc.latitude, loc.longitude]).addTo(map);

    marker.bindPopup(buildLocationPopup(loc));
    marker.on("click", () => {
      renderLocationDetails(loc, marker, markerByLocation);
    });

    markerByLocation.set(String(loc.id), marker);
  });

  renderLocationList(markerByLocation);
};

const initializeSearchMap = async () => {
  const mapElement = document.querySelector("#map");
  if (!mapElement) {
    return;
  }

  const map = L.map('map').setView([47.1622, 27.5889], 13);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  }).addTo(map);

  delete L.Icon.Default.prototype._getIconUrl;
  L.Icon.Default.mergeOptions({
    iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
    iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
    shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
  });

  // Funcția care citește filtrele normale și reface harta
  const fetchFilteredLocations = async () => {
    const params = new URLSearchParams();
    
    const qInput = document.querySelector('#search-query');
    if (qInput && qInput.value) params.append('q', qInput.value);

    const activeChip = document.querySelector('.filter-chips .chip.active');
    
    if (activeChip && activeChip.textContent !== 'All') {
      params.append('sport', activeChip.textContent);
    }

    try {
      const payload = await apiRequest(`backend/public/locations.php?${params.toString()}`);
      renderMapLocations(map, payload.locations || []);
    } catch (error) {
      console.error("Could not load map locations:", error);
    }
  };

  const fetchAdvancedLocations = async () => {
    const params = new URLSearchParams();

    const sportSelect = document.querySelector('.advanced-grid [name="sport"]');
    if (sportSelect && sportSelect.value !== 'Any sport') {
      params.append('sport', sportSelect.value);
    }

    const areaSelect = document.querySelector('.advanced-grid [name="area"]');
    if (areaSelect && areaSelect.value !== 'All of Iasi') {
      params.append('area', areaSelect.value);
    }

    const dateInput = document.querySelector('.advanced-grid [name="date"]');
    if (dateInput && dateInput.value) {
      const val = dateInput.value.trim();
      const parts = val.split('/');
      if (parts.length === 3) {
        params.append('date', `${parts[2]}-${parts[1]}-${parts[0]}`);
      } else {
        params.append('date', val);
      }
    }

    const levelSelect = document.querySelector('.advanced-grid [name="level"]');
    if (levelSelect && levelSelect.value !== 'Any level') {
      params.append('level', levelSelect.value);
    }

    const spotsInput = document.querySelector('.advanced-grid [name="min_spots"]');
    if (spotsInput && spotsInput.value) {
      params.append('min_spots', spotsInput.value);
    }


    try {
      const payload = await apiRequest(`backend/public/advanced-locations.php?${params.toString()}`);
      renderMapLocations(map, payload.locations || []);
    } catch (error) {
      console.error("Could not load map locations:", error);
    }
  };

  // 1. Încărcarea inițială
  await fetchFilteredLocations();

  // 2. Ascultători pentru formulare
  document.querySelector('.search-box')?.addEventListener('submit', (e) => {
    e.preventDefault();
    fetchFilteredLocations();
  });

  document.querySelector('.advanced-grid')?.addEventListener('submit', (e) => {
    e.preventDefault();
    document.querySelectorAll('.filter-chips .chip').forEach(chip => {
      if (chip.textContent === 'All') {
        chip.classList.add('active');
      } else {
        chip.classList.remove('active');
      }
    });
    fetchAdvancedLocations();
  });

  // 3. Ascultători pentru cipurile rapide (Football, Tennis, etc)
  document.querySelectorAll('.filter-chips .chip').forEach(chip => {
    chip.addEventListener('click', () => {
      // Un timeout scurt ca să lăsăm codul principal de UI să seteze clasa "active" mai întâi
      setTimeout(fetchFilteredLocations, 0);
    });
  });
};

loadEventsList();
initializeCreateEventForm();
loadEventDetails();
initializeSearchMap();

const initializeEmailRssButton = () => {
  const emailBtn = document.querySelector("#email-rss-btn");
  const msgSpan = document.querySelector("#email-rss-message");

  if (!emailBtn) return;

  emailBtn.addEventListener("click", async () => {
    emailBtn.disabled = true;
    msgSpan.textContent = "Sending email...";
    msgSpan.style.color = "inherit";

    try {
      const response = await apiRequest("../backend/public/email-events.php", {
        method: "POST"
      });
      msgSpan.textContent = response.message || "Email sent!";
      msgSpan.style.color = "var(--color-primary)";
    } catch (error) {
      const errorPayload = error.payload || {};
      if (errorPayload.message === "Authentication required.") {
        window.location.assign("login.html");
        return;
      }
      msgSpan.textContent = errorPayload.message || error.message;
      msgSpan.style.color = "var(--color-danger)";
    } finally {
      emailBtn.disabled = false;
    }
  });
};

initializeEmailRssButton();
