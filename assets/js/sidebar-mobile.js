(function () {
  function initMobileSidebar() {
    var sidebar = document.querySelector(".sidebar");
    if (!sidebar) {
      return;
    }

    if (!sidebar.id) {
      sidebar.id = "app-sidebar";
    }

    var toggle = document.querySelector("[data-sidebar-toggle]");
    if (!toggle) {
      toggle = document.createElement("button");
      toggle.type = "button";
      toggle.className = "sidebar-toggle";
      toggle.setAttribute("data-sidebar-toggle", "true");
      toggle.setAttribute("aria-controls", sidebar.id);
      toggle.setAttribute("aria-expanded", "false");
      toggle.setAttribute("aria-label", "Open navigation menu");

      for (var i = 0; i < 3; i++) {
        var bar = document.createElement("span");
        toggle.appendChild(bar);
      }

      document.body.appendChild(toggle);
    }

    var overlay = document.querySelector(".sidebar-overlay");
    if (!overlay) {
      overlay = document.createElement("button");
      overlay.type = "button";
      overlay.className = "sidebar-overlay";
      overlay.setAttribute("aria-label", "Close navigation menu");
      overlay.setAttribute("tabindex", "-1");
      document.body.appendChild(overlay);
    }

    var lastFocused = null;

    function isMobileLayout() {
      return window.matchMedia("(max-width: 1024px)").matches;
    }

    function openSidebar() {
      if (!isMobileLayout()) {
        return;
      }

      lastFocused = document.activeElement;
      sidebar.classList.add("active");
      overlay.classList.add("active");
      document.body.classList.add("sidebar-open");
      toggle.setAttribute("aria-expanded", "true");
      toggle.setAttribute("aria-label", "Close navigation menu");

      var firstNavItem = sidebar.querySelector("a, button");
      if (firstNavItem) {
        firstNavItem.focus();
      }
    }

    function closeSidebar() {
      sidebar.classList.remove("active");
      overlay.classList.remove("active");
      document.body.classList.remove("sidebar-open");
      toggle.setAttribute("aria-expanded", "false");
      toggle.setAttribute("aria-label", "Open navigation menu");

      if (lastFocused && typeof lastFocused.focus === "function") {
        lastFocused.focus();
      }
    }

    function toggleSidebar() {
      if (sidebar.classList.contains("active")) {
        closeSidebar();
      } else {
        openSidebar();
      }
    }

    toggle.addEventListener("click", toggleSidebar);
    overlay.addEventListener("click", closeSidebar);

    sidebar.addEventListener("click", function (event) {
      var navLink = event.target.closest("a");
      if (navLink && isMobileLayout()) {
        closeSidebar();
      }
    });

    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && sidebar.classList.contains("active")) {
        closeSidebar();
      }
    });

    window.addEventListener("resize", function () {
      if (!isMobileLayout()) {
        closeSidebar();
      }
    });

    // ── Logout confirmation with SweetAlert2 ──
    var logoutLinks = sidebar.querySelectorAll("a[href*='logout.php']");
    logoutLinks.forEach(function(link) {
      link.addEventListener("click", function(e) {
        e.preventDefault();
        
        if (typeof Swal !== 'undefined' && typeof sweetAlertUtils !== 'undefined') {
          (async function() {
            var result = await sweetAlertUtils.confirmLogout();
            if (result.isConfirmed) {
              window.location.href = link.href;
            }
          })();
        } else {
          // Fallback to native confirm if SweetAlert2 not available
          if (confirm('Are you sure you want to sign out?')) {
            window.location.href = link.href;
          }
        }
      });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initMobileSidebar);
  } else {
    initMobileSidebar();
  }
})();
