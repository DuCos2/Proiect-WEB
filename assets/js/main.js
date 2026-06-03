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
      planList.innerHTML = eventItem.plan
        .map(([time, label]) => `<li><span>${time}</span><strong>${label}</strong></li>`)
        .join("");
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
