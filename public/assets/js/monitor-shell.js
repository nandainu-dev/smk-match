(() => {
    "use strict";

    const shell = document.getElementById("monitor-shell");

    if (!(shell instanceof HTMLElement)) {
        return;
    }

    const slides = Array.from(shell.querySelectorAll("[data-monitor-slide]"));
    const indicators = Array.from(shell.querySelectorAll("[data-monitor-target]"));
    const order = slides.map((slide) => slide.getAttribute("data-monitor-slide"));
    let activeIndex = 0;

    function showSlide(index) {
        if (index < 0 || index >= slides.length) {
            return;
        }

        activeIndex = index;
        const activeName = order[activeIndex];

        slides.forEach((slide) => {
            const isActive = slide.getAttribute("data-monitor-slide") === activeName;
            slide.hidden = !isActive;
            slide.classList.toggle("is-active", isActive);
        });

        indicators.forEach((indicator) => {
            const isActive = indicator.getAttribute("data-monitor-target") === activeName;
            indicator.classList.toggle("is-active", isActive);
            indicator.setAttribute("aria-selected", String(isActive));
        });
    }

    indicators.forEach((indicator) => {
        indicator.addEventListener("click", () => {
            const target = indicator.getAttribute("data-monitor-target");
            const targetIndex = order.indexOf(target);
            showSlide(targetIndex);
        });
    });

    shell.querySelectorAll("[data-monitor-direction]").forEach((button) => {
        button.addEventListener("click", () => {
            const direction = button.getAttribute("data-monitor-direction");
            const nextIndex = direction === "next"
                ? (activeIndex + 1) % slides.length
                : (activeIndex - 1 + slides.length) % slides.length;
            showSlide(nextIndex);
        });
    });

    showSlide(activeIndex);
})();
