(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const body = document.body;
  const html = document.documentElement;

  const drawer = $("#drawer");
  const openBtns = $$("[data-drawer-open]");
  const closeBtns = $$("[data-drawer-close]");
  const backdrop = $(".backdrop");

  // -----------------------------
  // 1) Header show on scroll
  // -----------------------------
  function updateHeader() {
    const y = window.scrollY || 0;
    if (y > 8) body.classList.add("is-scrolled");
    else body.classList.remove("is-scrolled");
  }
  window.addEventListener("scroll", updateHeader, { passive: true });
  window.addEventListener("resize", updateHeader);
  updateHeader();

  // -----------------------------
  // 2) Reveal blur (sections)
  // -----------------------------
  const revealEls = $$("[data-reveal]");
  const revealIO = new IntersectionObserver((entries) => {
    entries.forEach((e) => {
      if (e.isIntersecting && e.intersectionRatio >= 0.22) {
        e.target.classList.add("is-revealed");
      } else {
        e.target.classList.remove("is-revealed");
      }
    });
  }, {
    threshold: [0, 0.12, 0.22, 0.35],
    rootMargin: "0px 0px -10% 0px"
  });
  revealEls.forEach((el) => revealIO.observe(el));

  // -----------------------------
  // 3) Drawer (no scroll jump)
  // -----------------------------
  let lastFocused = null;
  let savedPaddingRight = "";
  let savedOverflowBody = "";
  let savedOverflowHtml = "";

  function setExpanded(on) {
    openBtns.forEach((b) => b.setAttribute("aria-expanded", String(on)));
  }

  function scrollbarWidth() {
    return Math.max(0, window.innerWidth - document.documentElement.clientWidth);
  }

  function lockScrollNoJump() {
    const sw = scrollbarWidth();
    savedPaddingRight = body.style.paddingRight;
    savedOverflowBody = body.style.overflow;
    savedOverflowHtml = html.style.overflow;

    if (sw > 0) body.style.paddingRight = `${sw}px`;
    html.style.overflow = "hidden";
    body.style.overflow = "hidden";
  }

  function unlockScrollNoJump() {
    body.style.paddingRight = savedPaddingRight;
    body.style.overflow = savedOverflowBody;
    html.style.overflow = savedOverflowHtml;
  }

  function openDrawer() {
    if (!drawer) return;
    lastFocused = document.activeElement;

    body.classList.add("drawer-open");
    html.classList.add("drawer-open");
    drawer.setAttribute("aria-hidden", "false");
    setExpanded(true);

    lockScrollNoJump();
  }

  function closeDrawer() {
    if (!drawer) return;

    body.classList.remove("drawer-open");
    html.classList.remove("drawer-open");
    drawer.setAttribute("aria-hidden", "true");
    setExpanded(false);

    unlockScrollNoJump();

    if (lastFocused && typeof lastFocused.focus === "function") {
      try { lastFocused.focus({ preventScroll: true }); }
      catch { lastFocused.focus(); }
    }
    lastFocused = null;
  }

  openBtns.forEach((b) => b.addEventListener("click", openDrawer));
  closeBtns.forEach((b) => b.addEventListener("click", closeDrawer));
  backdrop?.addEventListener("click", closeDrawer);

  window.addEventListener("keydown", (e) => {
    if (!body.classList.contains("drawer-open")) return;
    if (e.key === "Escape") {
      e.preventDefault();
      closeDrawer();
    }
  });

  // -----------------------------
  // 4) Module conditional questions
  // -----------------------------
  const budgetChk = $("input[data-module='budget']");
  const prodChk = $("input[data-module='production']");
  const followBudget = $("#followBudget");
  const followProduction = $("#followProduction");

  function toggleFollow() {
    const budgetOn = !!(budgetChk && budgetChk.checked);
    const prodOn = !!(prodChk && prodChk.checked);

    if (followBudget) {
      followBudget.hidden = !budgetOn;
      if (!budgetOn) {
        // reset radios
        $$("input[type='radio']", followBudget).forEach(r => r.checked = false);
      }
    }

    if (followProduction) {
      followProduction.hidden = !prodOn;
      if (!prodOn) {
        // reset checks
        $$("input[type='checkbox']", followProduction).forEach(c => c.checked = false);
      }
    }
  }

  budgetChk?.addEventListener("change", toggleFollow);
  prodChk?.addEventListener("change", toggleFollow);

  // init
  toggleFollow();
})();
