(() => {
    "use strict";

    const POLLING_INTERVAL_MS = 5000;
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
    const state = {
        activeBatchId: null,
        knownResultIds: new Set(),
        visibleEvents: new Map(),
        reconciliationActive: false,
        reconciliationAfterResultId: 0,
        requestInFlight: false,
        pollTimer: null,
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

    function setStatus(message) {
        if (status instanceof HTMLElement) {
            status.textContent = message;
        }
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
            const outcomeKind = event.outcome?.kind;

            avatar.className = "monitor-avatar";
            avatar.textContent = participantName.slice(0, 1).toUpperCase() || "?";
            message.textContent = outcomeKind === "tie"
                ? `${participantName} menyelesaikan hasil setara.`
                : `${participantName} menyelesaikan kuis.`;
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
    renderVisibleEvents();
    poll();
})();
