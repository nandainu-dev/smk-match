(() => {
    "use strict";

    const POLLING_INTERVAL_MS = 5000;
    const SLIDE_DURATION_MS = 12000;
    const VISIBLE_EVENT_LIMIT = 20;
    const shell = document.getElementById("monitor-shell");

    if (!(shell instanceof HTMLElement)) {
        return;
    }

    const slides = Array.from(shell.querySelectorAll("[data-monitor-slide]"));
    const indicators = Array.from(shell.querySelectorAll("[data-monitor-target]"));
    const order = slides.map((slide) => slide.getAttribute("data-monitor-slide"));
    const activityList = shell.querySelector(".monitor-activity-list");
    const status = shell.querySelector(".monitor-shell-status");
    const alias = shell.dataset.monitorAlias;
    const qrTarget = shell.dataset.monitorQrTarget;
    const qrCode = shell.querySelector("[data-monitor-qr-code]");
    const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
    const state = {
        activeBatchId: null,
        knownResultIds: new Set(),
        visibleEvents: new Map(),
        reconciliationActive: false,
        reconciliationAfterResultId: 0,
        requestInFlight: false,
        pollTimer: null,
        slideTimer: null,
    };
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

    function clearSlideTimer() {
        if (state.slideTimer !== null) {
            window.clearTimeout(state.slideTimer);
            state.slideTimer = null;
        }
    }

    function autoRotationEnabled() {
        return !document.hidden && !reducedMotion.matches && slides.length > 1;
    }

    function scheduleSlideRotation() {
        clearSlideTimer();

        if (!autoRotationEnabled()) {
            return;
        }

        state.slideTimer = window.setTimeout(() => {
            showSlide((activeIndex + 1) % slides.length);
            scheduleSlideRotation();
        }, SLIDE_DURATION_MS);
    }

    function selectSlide(index) {
        showSlide(index);
        scheduleSlideRotation();
    }

    indicators.forEach((indicator) => {
        indicator.addEventListener("click", () => {
            const target = indicator.getAttribute("data-monitor-target");
            const targetIndex = order.indexOf(target);
            selectSlide(targetIndex);
        });
    });

    shell.querySelectorAll("[data-monitor-direction]").forEach((button) => {
        button.addEventListener("click", () => {
            const direction = button.getAttribute("data-monitor-direction");
            const nextIndex = direction === "next"
                ? (activeIndex + 1) % slides.length
                : (activeIndex - 1 + slides.length) % slides.length;
            selectSlide(nextIndex);
        });
    });

    document.addEventListener("visibilitychange", scheduleSlideRotation);
    reducedMotion.addEventListener("change", scheduleSlideRotation);

    function setStatus(message) {
        if (status instanceof HTMLElement) {
            status.textContent = message;
        }
    }

    function programLabel(program) {
        if (!program || typeof program !== "object") {
            return "program";
        }

        if (typeof program.name === "string" && program.name.trim() !== "") {
            return program.name;
        }

        return typeof program.code === "string" && program.code.trim() !== "" ? program.code : "program";
    }

    function formatPercentage(value) {
        return Number.isFinite(value) ? `${value.toFixed(1)}%` : "—";
    }

    function renderOverview(monitor) {
        const batch = monitor?.batch;
        const summary = monitor?.summary;
        const programs = Array.isArray(monitor?.programs) ? monitor.programs : [];
        const batchTarget = shell.querySelector("[data-monitor-overview-batch]");

        if (batchTarget instanceof HTMLElement) {
            const label = typeof batch?.label === "string" && batch.label.trim() !== ""
                ? batch.label
                : "Batch aktif";
            batchTarget.textContent = `${label} · Batch ${batch?.number ?? "—"}`;
        }

        const countTargets = [
            ["[data-monitor-started-count]", summary?.started_count],
            ["[data-monitor-completed-count]", summary?.completed_count],
            ["[data-monitor-tie-count]", summary?.tie_count],
        ];
        countTargets.forEach(([selector, value]) => {
            const target = shell.querySelector(selector);
            if (target instanceof HTMLElement) {
                target.textContent = Number.isInteger(value) && value >= 0 ? String(value) : "—";
            }
        });

        const metricTarget = shell.querySelector("[data-monitor-program-metrics]");
        if (!(metricTarget instanceof HTMLElement)) {
            return;
        }

        const rows = programs.map((program) => {
            const row = document.createElement("article");
            const name = document.createElement("strong");
            const metrics = document.createElement("span");

            row.className = "monitor-program-metric";
            name.textContent = programLabel(program);
            metrics.textContent = `${Number.isInteger(program?.dominant_count) ? program.dominant_count : "—"} dominan · ${formatPercentage(program?.score_average_percentage)}`;
            row.append(name, metrics);

            return row;
        });

        metricTarget.replaceChildren(...rows);
    }

    function localMascotPath(path) {
        if (typeof path !== "string" || path === "") {
            return null;
        }

        try {
            const candidate = new URL(path, window.location.origin);
            return candidate.origin === window.location.origin && candidate.pathname.startsWith("/assets/")
                ? `${candidate.pathname}${candidate.search}`
                : null;
        } catch (_error) {
            return null;
        }
    }

    function renderProgramSlide(slide, programs) {
        const code = slide.dataset.monitorProgramCode;
        const program = programs.find((candidate) => candidate?.code === code);
        const unavailable = slide.querySelector("[data-program-unavailable]");
        const title = slide.querySelector("[data-program-title]");
        const name = slide.querySelector("[data-program-name]");
        const description = slide.querySelector("[data-program-description]");
        const summary = slide.querySelector("[data-program-summary]");
        const dominantCount = slide.querySelector("[data-program-dominant-count]");
        const averagePercentage = slide.querySelector("[data-program-average-percentage]");
        const mascot = slide.querySelector("[data-program-mascot]");

        if (!program) {
            slide.classList.add("is-unavailable");
            if (unavailable instanceof HTMLElement) {
                unavailable.hidden = false;
            }
            if (summary instanceof HTMLElement) {
                summary.hidden = true;
            }
            return;
        }

        slide.classList.remove("is-unavailable");
        if (unavailable instanceof HTMLElement) {
            unavailable.hidden = true;
        }
        if (summary instanceof HTMLElement) {
            summary.hidden = false;
        }
        if (title instanceof HTMLElement) {
            title.textContent = typeof program.personality_title === "string" && program.personality_title.trim() !== ""
                ? program.personality_title
                : "PROGRAM SPOTLIGHT";
        }
        if (name instanceof HTMLElement) {
            name.textContent = programLabel(program);
        }
        if (description instanceof HTMLElement) {
            description.textContent = typeof program.description === "string" && program.description.trim() !== ""
                ? program.description
                : (typeof program.tagline === "string" && program.tagline.trim() !== "" ? program.tagline : "Snapshot program tersedia pada batch aktif.");
        }
        if (dominantCount instanceof HTMLElement) {
            dominantCount.textContent = Number.isInteger(program.dominant_count) ? String(program.dominant_count) : "—";
        }
        if (averagePercentage instanceof HTMLElement) {
            averagePercentage.textContent = formatPercentage(program.score_average_percentage);
        }
        if (mascot instanceof HTMLImageElement) {
            const path = localMascotPath(program.mascot_path);
            mascot.hidden = path === null;
            if (path !== null) {
                mascot.src = path;
                mascot.alt = `Maskot ${programLabel(program)}`;
            }
        }
    }

    function renderPresentation(monitor) {
        const programs = Array.isArray(monitor?.programs) ? monitor.programs : [];

        renderOverview(monitor);
        slides.forEach((slide) => {
            if (slide instanceof HTMLElement && slide.dataset.monitorProgramCode) {
                renderProgramSlide(slide, programs);
            }
        });
    }

    function renderQrCode() {
        if (!(qrCode instanceof HTMLElement) || typeof qrTarget !== "string" || qrTarget === "") {
            return;
        }

        try {
            window.SmkMatchQrSvg.render(qrCode, qrTarget, { foreground: "#25135d", background: "#ffffff" });
        } catch (_error) {
            qrCode.textContent = "QR tidak tersedia.";
        }
    }

    function activityMessage(event, participantName) {
        const outcome = event?.outcome;

        if (outcome?.kind === "tie") {
            const tiedPrograms = Array.isArray(outcome.tied_programs) ? outcome.tied_programs : [];
            const labels = tiedPrograms.map(programLabel).filter((label) => label !== "program");
            return labels.length > 0
                ? `${participantName} menyelesaikan hasil setara: ${labels.join(", ")}.`
                : `${participantName} menyelesaikan hasil setara.`;
        }

        if (outcome?.kind === "decisive" && outcome.dominant_program) {
            return `${participantName} menyelesaikan kuis: ${programLabel(outcome.dominant_program)}.`;
        }

        return `${participantName} menyelesaikan kuis.`;
    }

    function eventSortOrder(left, right) {
        const timeOrder = String(right.submitted_at || "").localeCompare(String(left.submitted_at || ""));

        if (timeOrder !== 0) {
            return timeOrder;
        }

        return Number(right.result_id) - Number(left.result_id);
    }

    function renderVisibleEvents() {
        if (!(activityList instanceof HTMLOListElement)) {
            return;
        }

        const events = Array.from(state.visibleEvents.values()).sort(eventSortOrder);
        const visibleEvents = events.slice(0, VISIBLE_EVENT_LIMIT);

        state.visibleEvents = new Map(visibleEvents.map((event) => [event.result_id, event]));

        if (visibleEvents.length === 0) {
            const placeholder = document.createElement("li");
            const avatar = document.createElement("span");
            const message = document.createElement("span");

            avatar.className = "monitor-avatar";
            avatar.textContent = "?";
            message.textContent = "Aktivitas peserta akan tampil di sini.";
            placeholder.append(avatar, message);
            activityList.replaceChildren(placeholder);
            return;
        }

        const rows = visibleEvents.map((event) => {
            const row = document.createElement("li");
            const avatar = document.createElement("span");
            const message = document.createElement("span");
            const participantName = typeof event.participant_name === "string" ? event.participant_name : "Peserta";

            avatar.className = "monitor-avatar";
            avatar.textContent = participantName.slice(0, 1).toUpperCase() || "?";
            message.textContent = activityMessage(event, participantName);
            row.append(avatar, message);

            return row;
        });

        activityList.replaceChildren(...rows);
    }

    function clearBatchState() {
        state.knownResultIds.clear();
        state.visibleEvents.clear();
        state.reconciliationActive = false;
        state.reconciliationAfterResultId = 0;
        renderVisibleEvents();
    }

    function addRecentEvents(events) {
        if (!Array.isArray(events)) {
            return;
        }

        events.forEach((event) => {
            if (!event || !Number.isInteger(event.result_id) || event.result_id < 1) {
                return;
            }

            state.knownResultIds.add(event.result_id);
            state.visibleEvents.set(event.result_id, event);
        });

        renderVisibleEvents();
    }

    function applySnapshot(monitor) {
        const batchId = monitor?.batch?.id;

        if (!Number.isInteger(batchId) || batchId < 1) {
            throw new Error("Invalid monitor batch.");
        }

        if (state.activeBatchId !== batchId) {
            state.activeBatchId = batchId;
            clearBatchState();
        }

        renderPresentation(monitor);
        addRecentEvents(monitor.recent_activity);
    }

    function completedCount(monitor) {
        const completedCount = monitor?.summary?.completed_count;

        if (!Number.isInteger(completedCount) || completedCount < 0) {
            throw new Error("Invalid monitor completed count.");
        }

        return completedCount;
    }

    function reconciliationIsNeeded(monitor) {
        return state.knownResultIds.size < completedCount(monitor);
    }

    function reconciliationIsComplete(monitor) {
        return state.knownResultIds.size === completedCount(monitor);
    }

    function endpoint(reconciliationAfterResultId = null) {
        const query = new URLSearchParams({ recentLimit: String(VISIBLE_EVENT_LIMIT) });

        if (reconciliationAfterResultId !== null) {
            query.set("reconciliationAfterResultId", String(reconciliationAfterResultId));
        }

        return `/api/public/monitor/${encodeURIComponent(alias || "")}?${query.toString()}`;
    }

    async function requestSnapshot(reconciliationAfterResultId = null) {
        const response = await window.fetch(endpoint(reconciliationAfterResultId), {
            headers: { Accept: "application/json" },
            cache: "no-store",
        });

        if (response.status === 404) {
            return null;
        }

        if (!response.ok) {
            throw new Error("Monitor request failed.");
        }

        const payload = await response.json();
        if (!payload || payload.ok !== true || !payload.monitor) {
            throw new Error("Invalid monitor response.");
        }

        return payload.monitor;
    }

    async function reconcile(monitor) {
        state.reconciliationActive = true;
        state.reconciliationAfterResultId = 0;
        setStatus("Menyelaraskan aktivitas monitor");

        while (state.reconciliationActive) {
            const reconciliationMonitor = await requestSnapshot(state.reconciliationAfterResultId);

            if (reconciliationMonitor === null) {
                state.activeBatchId = null;
                clearBatchState();
                setStatus("Monitor belum tersedia");
                return;
            }

            const previousBatchId = state.activeBatchId;
            applySnapshot(reconciliationMonitor);
            if (state.activeBatchId !== previousBatchId) {
                return;
            }

            const page = reconciliationMonitor.reconciliation;
            if (!page || !Array.isArray(page.result_ids) || typeof page.has_more !== "boolean") {
                throw new Error("Invalid reconciliation response.");
            }

            page.result_ids.forEach((resultId) => {
                if (Number.isInteger(resultId) && resultId > 0) {
                    state.knownResultIds.add(resultId);
                }
            });

            if (page.has_more) {
                if (!Number.isInteger(page.next_page_after_result_id) || page.next_page_after_result_id < 1) {
                    throw new Error("Invalid reconciliation page position.");
                }

                state.reconciliationAfterResultId = page.next_page_after_result_id;
                continue;
            }

            if (reconciliationIsComplete(reconciliationMonitor)) {
                state.reconciliationActive = false;
                setStatus("Monitor aktif");
                return;
            }

            if (reconciliationIsNeeded(reconciliationMonitor)) {
                state.reconciliationAfterResultId = 0;
                continue;
            }

            throw new Error("Monitor reconciliation count is inconsistent.");
        }
    }

    function schedulePoll() {
        if (state.pollTimer !== null) {
            window.clearTimeout(state.pollTimer);
        }

        state.pollTimer = window.setTimeout(poll, POLLING_INTERVAL_MS);
    }

    async function poll() {
        if (state.requestInFlight || state.reconciliationActive) {
            return;
        }

        state.requestInFlight = true;

        try {
            const monitor = await requestSnapshot();

            if (monitor === null) {
                state.activeBatchId = null;
                clearBatchState();
                setStatus("Monitor belum tersedia");
                return;
            }

            applySnapshot(monitor);
            if (reconciliationIsComplete(monitor)) {
                setStatus("Monitor aktif");
            } else if (reconciliationIsNeeded(monitor)) {
                await reconcile(monitor);
            } else {
                throw new Error("Monitor reconciliation count is inconsistent.");
            }
        } catch (_error) {
            setStatus("Mencoba menghubungkan monitor");
        } finally {
            state.requestInFlight = false;
            schedulePoll();
        }
    }

    showSlide(activeIndex);
    scheduleSlideRotation();
    renderQrCode();
    renderVisibleEvents();
    poll();
})();
