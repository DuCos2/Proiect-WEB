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

  return new Intl.DateTimeFormat("en", {
    month: "short",
    day: "numeric"
  }).format(parsedDate);
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
      empty.innerHTML = '<div class="card-body"><h3>No events yet</h3><p>Be the first person to add one.</p></div>';
      eventsRoot.append(empty);
      updateFeaturedEvent(null);
      return;
    }

    updateFeaturedEvent(payload.events[0]);

    payload.events.forEach((eventItem) => {
      eventsRoot.append(renderEventCard(eventItem));
    });
  } catch (error) {
    eventsRoot.innerHTML = '<article class="event-card"><div class="card-body"><h3>Events could not be loaded</h3><p>Try refreshing the page.</p></div></article>';
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
  if (dateInput) {
    const now = new Date();
    const tzOffset = now.getTimezoneOffset() * 60000;
    const localISOTime = new Date(now - tzOffset).toISOString().slice(0, 16);
    dateInput.min = localISOTime;
  }

  const locationSelect = form.querySelector('select[name="location"]');
  const sportSelect = form.querySelector('select[name="sport"]');
  const areaSelect = form.querySelector('select[name="area"]');
  let locationsList = [];

  if (locationSelect && sportSelect) {
    try {
      const locationsPayload = await apiRequest("backend/public/locations.php");
      locationsList = locationsPayload.locations || [];

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

    locationSelect.addEventListener("change", () => {
      const selectedName = locationSelect.value;
      const selectedLoc = locationsList.find(loc => loc.name === selectedName);

      if (selectedLoc) {
        if (areaSelect) {
          areaSelect.value = selectedLoc.area;
        }

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
        if (areaSelect) {
          areaSelect.value = "";
        }
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
    const selectedLevels = Array.from(form.querySelectorAll('input[name="skill_levels"]:checked')).map(checkbox => checkbox.value);

    let skillLevelString = "";
    if (selectedLevels.length === 3) {
      skillLevelString = "All levels";
    } else if (selectedLevels.length > 0) {
      skillLevelString = selectedLevels.join(", ");
    } else {
      skillLevelString = "Mixed level";
    }
    payload.skill_level = skillLevelString;
    delete payload.skill_levels;

    setFormErrors(form);
    setEventFormMessage(form, "Saving event...");

    if (submitButton) {
      submitButton.disabled = true;
    }

    try {
      const csrfToken = await getCsrfToken();
      const result = await apiRequest("backend/public/events.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
        },
        body: JSON.stringify(payload)
      });

      form.reset();
      if (sportSelect) {
        sportSelect.disabled = true;
        sportSelect.innerHTML = '<option value="" disabled selected>First select the location...</option>';
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

  [
    ["Meet", `${eventTimeLabel(eventItem.eventDate)} at ${eventItem.location.name}`],
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
    item.innerHTML = "<span>No participants yet</span>";
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
      const csrfToken = await getCsrfToken();
      const result = await apiRequest("backend/public/event-join.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
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
      const csrfToken = await getCsrfToken();
      await apiRequest(`backend/public/event.php?id=${encodeURIComponent(eventItem.id)}`, {
        method: "DELETE",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": csrfToken
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
  setPageText("#detail-date", eventDateLabel(eventItem.eventDate));
  setPageText("#detail-time", eventTimeLabel(eventItem.eventDate));
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
// Map Initialization and Rendering using Leaflet & OpenStreetMap
const renderMapLocations = (map, locations) => {
  const resultsRoot = document.querySelector(".results-panel");

  if (resultsRoot) {
    resultsRoot.replaceChildren();

    const heading = document.createElement("div");
    heading.className = "section-heading compact";
    heading.innerHTML = '<div><p class="eyebrow">Nearby</p><h2>Venues and events</h2></div>';
    resultsRoot.append(heading);

    if (!locations.length) {
      const empty = document.createElement("p");
      empty.className = "venue-item";
      empty.innerHTML = "<div><h3>No venues found</h3><p>Try again later.</p></div>";
      resultsRoot.append(empty);
    }
  }

  locations.forEach((loc) => {
    // Add Marker to Leaflet Map
    const marker = L.marker([loc.latitude, loc.longitude]).addTo(map);

    // Bind description popup
    const sportsText = loc.sports ? `<br><strong>Sports:</strong> ${loc.sports}` : "";
    const popupContent = `
      <div style="font-family: inherit; color: var(--ink);">
        <strong style="font-size: 1.1rem; display: block; margin-bottom: 0.2rem;">${loc.name}</strong>
        <span style="color: var(--muted); font-size: 0.9rem;">${loc.address}</span><br>
        <span style="font-size: 0.9rem;">Area: <strong>${loc.area}</strong></span>
        ${sportsText}
      </div>
    `;
    marker.bindPopup(popupContent);

    // Render in Sidebar
    if (resultsRoot) {
      const article = document.createElement("article");
      article.className = "venue-item";
      article.style.cursor = "pointer";

      article.innerHTML = `
        <div>
          <h3>${loc.name}</h3>
          <p>${loc.sports || "No sports registered"}</p>
        </div>
        <span>${loc.activeEventsCount} events</span>
      `;

      // Focus map and open popup on click
      article.addEventListener("click", () => {
        map.setView([loc.latitude, loc.longitude], 15);
        marker.openPopup();
      });

      resultsRoot.append(article);
    }
  });
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

  try {
    const payload = await apiRequest("backend/public/locations.php");
    renderMapLocations(map, payload.locations || []);
  } catch (error) {
    console.error("Could not load map locations:", error);
  }
};

loadEventsList();
initializeCreateEventForm();
loadEventDetails();
initializeSearchMap();