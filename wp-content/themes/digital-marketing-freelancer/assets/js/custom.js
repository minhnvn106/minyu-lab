// Home Sidebar
document.addEventListener("DOMContentLoaded", () => {
  const digital_marketing_freelancer_toggle = document.querySelector(".top-header-info-btn a"),
        digital_marketing_freelancer_info = document.querySelector(".top-header-info"),
        digital_marketing_freelancer_close = document.querySelector(".header-info-close a");

  if (!digital_marketing_freelancer_toggle || !digital_marketing_freelancer_info) return;

  digital_marketing_freelancer_toggle.onclick = () => (digital_marketing_freelancer_info.classList.add("active"), document.body.classList.add("top-header-info-open"));
  digital_marketing_freelancer_close && (digital_marketing_freelancer_close.onclick = () => (digital_marketing_freelancer_info.classList.remove("active"), document.body.classList.remove("top-header-info-open")));

  document.addEventListener("click", e => {
    if (document.body.classList.contains("top-header-info-open") &&
        !digital_marketing_freelancer_info.contains(e.target) && !digital_marketing_freelancer_toggle.contains(e.target)) {
      digital_marketing_freelancer_info.classList.remove("active");
      document.body.classList.remove("top-header-info-open");
    }
  });
});

document.addEventListener("DOMContentLoaded", () => {
  const digital_marketing_freelancer_toggleBtn = document.querySelector(".top-header-info-btn a"); // open button
  const digital_marketing_freelancer_infoBox = document.querySelector(".top-header-info"); // info box
  const digital_marketing_freelancer_closeBtn = document.querySelector(".header-info-close a"); // close button

  if (!digital_marketing_freelancer_toggleBtn || !digital_marketing_freelancer_infoBox || !digital_marketing_freelancer_closeBtn) return;

  // OPEN the info box
  digital_marketing_freelancer_toggleBtn.addEventListener("click", () => {
    digital_marketing_freelancer_infoBox.classList.add("active");
    document.body.classList.add("top-header-info-active");

    // Optional: move keyboard focus inside
    const firstFocusable = digital_marketing_freelancer_infoBox.querySelector(
      'a, button, input, textarea, select, [tabindex]:not([tabindex="-1"])'
    );
    if (firstFocusable) firstFocusable.focus();
  });

  // CLOSE the info box (when clicking the close icon)
  digital_marketing_freelancer_closeBtn.addEventListener("click", () => {
    digital_marketing_freelancer_infoBox.classList.remove("active");
    document.body.classList.remove("top-header-info-active");
    digital_marketing_freelancer_toggleBtn.focus(); // return focus to the button
  });

  // Trap focus inside when active
  document.addEventListener("keydown", (e) => {
    if (!digital_marketing_freelancer_infoBox.classList.contains("active")) return;

    const focusable = digital_marketing_freelancer_infoBox.querySelectorAll(
      'a, button, input, textarea, select, [tabindex]:not([tabindex="-1"])'
    );
    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (e.key === "Tab") {
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }

    // ESC key also closes the box
    if (e.key === "Escape") {
      digital_marketing_freelancer_closeBtn.click();
    }
  });
});