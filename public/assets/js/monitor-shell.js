(() => {
    "use strict";

    const POLLING_INTERVAL_MS = 5000;
    const SLIDE_DURATION_MS = 12000;
    const VISIBLE_EVENT_LIMIT = 20;
    const DISPLAY_EVENT_LIMIT = 5;
    const ACTIVITY_ROTATION_MS = 7000;
    const shell = document.getElementById("monitor-shell");

    if (!(shell instanceof HTMLElement)) {
        return;
    }

    const slides = Array.from(shell.querySelectorAll("[data-monitor-slide]"));
    const indicators = Array.from(shell.querySelectorAll("[data-monitor-target]"));
    const programIndicators = Array.from(shell.querySelectorAll("[data-program-indicator]"));
    const order = slides.map((slide) => slide.getAttribute("data-monitor-slide"));
    const activityList = shell.querySelector(".monitor-activity-list");
    const status = shell.querySelector(".monitor-shell-status");
    const alias = shell.dataset.monitorAlias;
    const qrTarget = shell.dataset.monitorQrTarget;
    const qrCode = shell.querySelector("[data-monitor-qr-code]");
    const monitorFooter = shell.querySelector("[data-monitor-footer]");
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
        activityTimer: null,
        activityOffset: 0,
        footerKey: null,
        programsByCode: new Map(),
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

    document.addEventListener("visibilitychange", () => {
        scheduleSlideRotation();
        scheduleActivityRotation();
    });
    reducedMotion.addEventListener("change", () => {
        scheduleSlideRotation();
        scheduleActivityRotation();
    });

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

    function programCode(program) {
        return typeof program?.code === "string" && program.code.trim() !== ""
            ? program.code.trim()
            : programLabel(program);
    }

    function formatPercentage(value) {
        return Number.isFinite(value) ? `${value.toFixed(1)}%` : "—";
    }

    function programColor(program, fallback) {
        const candidate = typeof program?.primary_color === "string" ? program.primary_color.trim() : "";
        return /^#[0-9a-f]{6}$/i.test(candidate) ? candidate : fallback;
    }

    function programAccent(program, fallback) {
        const candidate = typeof program?.accent_color === "string" ? program.accent_color.trim() : "";
        return /^#[0-9a-f]{6}$/i.test(candidate) ? candidate : fallback;
    }

    function programList(value) {
        if (typeof value !== "string" || value.trim() === "") {
            return [];
        }

        try {
            const parsed = JSON.parse(value);
            if (Array.isArray(parsed)) {
                return parsed.filter((item) => typeof item === "string" && item.trim() !== "").map((item) => item.trim());
            }
        } catch (_error) {
            // Legacy snapshots may store a plain, comma-delimited description.
        }

        return value.split(/[,\n•]/).map((item) => item.trim()).filter(Boolean);
    }

    function renderChipList(target, values, emptyLabel) {
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const items = values.length > 0 ? values : [emptyLabel];
        target.replaceChildren(...items.map((value) => {
            const chip = document.createElement("span");
            chip.className = "monitor-chip";
            chip.textContent = `✦ ${value}`;
            return chip;
        }));
    }

    function renderOverview(monitor) {
        const summary = monitor?.summary;
        const programs = Array.isArray(monitor?.programs) ? monitor.programs : [];
        const batchTarget = shell.querySelector("[data-monitor-overview-batch]");

        if (batchTarget instanceof HTMLElement) {
            batchTarget.textContent = "Scan QR di samping kanan, jawab 5 pertanyaan seru, dan temukan karakter jurusan SMK-mu!";
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

        const rosterTarget = shell.querySelector("[data-monitor-program-roster]");
        if (rosterTarget instanceof HTMLElement) {
            rosterTarget.replaceChildren(...programs.map((program, index) => {
                const row = document.createElement("article");
                const path = localPresentationImagePath(program?.mascot_path);
                const fallbackColors = ["#ee3f9a", "#0db9d8", "#f3a316"];
                const color = programColor(program, fallbackColors[index % fallbackColors.length]);
                const copy = document.createElement("div");
                const name = document.createElement("strong");
                const badge = document.createElement("span");

                row.className = "monitor-roster-program";
                row.style.setProperty("--program-color", color);
                if (path !== null) {
                    const image = document.createElement("img");
                    image.src = path;
                    image.alt = "";
                    row.append(image);
                } else {
                    const placeholder = document.createElement("span");
                    placeholder.className = "monitor-roster-placeholder";
                    placeholder.setAttribute("aria-hidden", "true");
                    row.append(placeholder);
                }
                name.textContent = programLabel(program);
                badge.textContent = programCode(program);
                copy.append(name, badge);
                row.append(copy);
                return row;
            }));
        }

        const metricTarget = shell.querySelector("[data-monitor-program-metrics]");
        if (!(metricTarget instanceof HTMLElement)) {
            return;
        }

        const rows = programs.map((program, index) => {
            const row = document.createElement("article");
            const name = document.createElement("strong");
            const metrics = document.createElement("span");
            const percent = Number.isFinite(program?.score_average_percentage) ? Math.max(0, Math.min(100, program.score_average_percentage)) : 0;
            const fallbackColors = ["#ee3f9a", "#0db9d8", "#f3a316"];

            row.className = "monitor-program-metric";
            row.style.setProperty("--program-color", programColor(program, fallbackColors[index % fallbackColors.length]));
            row.style.setProperty("--program-percent", `${percent}%`);
            name.textContent = programLabel(program);
            metrics.innerHTML = `<b>${formatPercentage(program?.score_average_percentage)}</b> ${Number.isInteger(program?.dominant_count) ? `${program.dominant_count} peserta` : "—"}`;
            row.append(name, metrics);

            return row;
        });

        metricTarget.replaceChildren(...rows);
    }

    function localPresentationImagePath(path) {
        if (typeof path !== "string" || path === "") {
            return null;
        }

        try {
            const candidate = new URL(path, window.location.origin);
            return candidate.origin === window.location.origin
                && (candidate.pathname.startsWith("/assets/") || candidate.pathname.startsWith("/uploads/programs/"))
                ? `${candidate.pathname}${candidate.search}`
                : null;
        } catch (_error) {
            return null;
        }
    }

    function renderProgramSlide(slide, programs) {
        const slot = Number(slide.dataset.monitorProgramSlot);
        const program = Number.isInteger(slot) && slot >= 0 ? programs[slot] : null;
        const indicator = programIndicators.find((candidate) => candidate.getAttribute("data-monitor-target") === slide.dataset.monitorSlide);
        const unavailable = slide.querySelector("[data-program-unavailable]");
        const title = slide.querySelector("[data-program-title]");
        const name = slide.querySelector("[data-program-name]");
        const description = slide.querySelector("[data-program-description]");
        const summary = slide.querySelector("[data-program-summary]");
        const dominantCount = slide.querySelector("[data-program-dominant-count]");
        const averagePercentage = slide.querySelector("[data-program-average-percentage]");
        const monitorImage = slide.querySelector("[data-program-monitor-image]");
        const imageEmpty = slide.querySelector("[data-program-image-empty]");
        const skills = slide.querySelector("[data-program-skills]");
        const careers = slide.querySelector("[data-program-careers]");

        if (!program) {
            if (indicator instanceof HTMLElement) {
                indicator.textContent = Number.isInteger(slot) ? `Program ${slot + 1}` : "Program";
            }
            slide.classList.add("is-unavailable");
            if (unavailable instanceof HTMLElement) {
                unavailable.hidden = false;
            }
            if (summary instanceof HTMLElement) {
                summary.hidden = true;
            }
            renderChipList(skills, [], "Belum tersedia");
            renderChipList(careers, [], "Belum tersedia");
            return;
        }

        slide.classList.remove("is-unavailable");
        const fallbackColors = ["#ee3f9a", "#0db9d8", "#f3a316"];
        const color = programColor(program, fallbackColors[slot % fallbackColors.length]);
        slide.style.setProperty("--program-color", color);
        slide.style.setProperty("--program-accent", programAccent(program, "#baff00"));
        if (indicator instanceof HTMLElement) {
            indicator.textContent = programLabel(program);
        }
        if (unavailable instanceof HTMLElement) {
            unavailable.hidden = true;
        }
        if (summary instanceof HTMLElement) {
            summary.hidden = false;
        }
        if (title instanceof HTMLElement) {
            title.textContent = programCode(program);
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
        renderChipList(skills, programList(program.skills), "Snapshot skill belum tersedia");
        renderChipList(careers, programList(program.careers), "Snapshot karir belum tersedia");
        if (monitorImage instanceof HTMLImageElement) {
            const path = localPresentationImagePath(program.monitor_image_path);
            monitorImage.hidden = path === null;
            if (path !== null) {
                monitorImage.src = path;
                monitorImage.alt = `Gambar monitor ${programLabel(program)}`;
            } else {
                monitorImage.removeAttribute("src");
            }
        }
        if (imageEmpty instanceof HTMLElement) {
            imageEmpty.hidden = localPresentationImagePath(program.monitor_image_path) !== null;
        }
    }

    function renderPresentation(monitor) {
        const programs = Array.isArray(monitor?.programs) ? monitor.programs : [];
        const configuredPrograms = programs.length === 3 ? programs : [];
        state.programsByCode = new Map();
        programs.forEach((program, index) => {
            const code = typeof program?.code === "string"
                ? program.code.trim().toUpperCase()
                : "";

            if (code !== "") {
                state.programsByCode.set(code, {
                    program,
                    index
                });
            }
        });


        renderOverview(monitor);
        renderFooter(monitor);
        if (programs.length !== 3) {
            setStatus("Konfigurasi batch harus berisi tiga program");
        }
        slides.forEach((slide) => {
            if (slide instanceof HTMLElement && slide.dataset.monitorProgramSlot !== undefined) {
                renderProgramSlide(slide, configuredPrograms);
            }
        });
    }

    function localFooterLogoPath(value) {
        return typeof value === "string" && /^\/uploads\/programs\/[a-f0-9]{64}\.(?:jpg|png|webp)$/i.test(value)
            ? value
            : null;
    }

    function renderFooter(monitor) {
        if (!(monitorFooter instanceof HTMLElement) || !monitor?.footer || typeof monitor.footer !== "object") {
            return;
        }

        const logoPath = localFooterLogoPath(monitor.footer.footer_logo_path);
        const configuredText = typeof monitor.footer.footer_text === "string" ? monitor.footer.footer_text.trim() : "";
        const footerText = configuredText || "SMK MATCH • Student Potential Exploration";
        const footerKey = `${logoPath || ""}\u0000${footerText}`;
        if (state.footerKey === footerKey) {
            return;
        }

        const markOrLogo = logoPath === null ? document.createElement("span") : document.createElement("img");
        if (markOrLogo instanceof HTMLImageElement) {
            markOrLogo.src = logoPath;
            markOrLogo.alt = "Logo sekolah";
        } else {
            markOrLogo.className = "monitor-footer__mark";
            markOrLogo.setAttribute("aria-hidden", "true");
            markOrLogo.textContent = "✦";
        }

        const text = document.createElement("p");
        text.textContent = footerText;
        monitorFooter.replaceChildren(markOrLogo, text);
        state.footerKey = footerKey;
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

    function activityMessageSuffix(event) {
        const outcome = event?.outcome;

        if (outcome?.kind === "tie") {
            return ", Anda cocok di beberapa jurusan setara.";
        }

        if (outcome?.kind === "decisive" && outcome.dominant_program) {
            return `, Anda cocok di ${programLabel(outcome.dominant_program)}.`;
        }

        return ", hasil quiz Anda sudah tersedia.";
    }

    function eventSortOrder(left, right) {
        const timeOrder = String(right.submitted_at || "").localeCompare(String(left.submitted_at || ""));

        if (timeOrder !== 0) {
            return timeOrder;
        }

        return Number(right.result_id) - Number(left.result_id);
    }

    function resolveEventProgram(program) {
        if (!program || typeof program !== "object") {
            return program;
        }

        const code = typeof program.code === "string"
            ? program.code.trim().toUpperCase()
            : "";

        if (code === "") {
            return program;
        }

        return state.programsByCode.get(code)?.program ?? program;
    }

    function eventProgramColor(program, fallback) {
        const code = typeof program?.code === "string"
            ? program.code.trim().toUpperCase()
            : "";

        const entry = code !== ""
            ? state.programsByCode.get(code)
            : null;

        const fallbackColors = [
            "#ee3f9a",
            "#0db9d8",
            "#f3a316"
        ];

        return entry
            ? programColor(
                entry.program,
                fallbackColors[entry.index % fallbackColors.length]
            )
            : programColor(program, fallback);
    }
    function renderVisibleEvents() {
        if (!(activityList instanceof HTMLOListElement)) {
            return;
        }

        const events = Array.from(state.visibleEvents.values()).sort(eventSortOrder).slice(0, VISIBLE_EVENT_LIMIT);
        state.visibleEvents = new Map(events.map((event) => [event.result_id, event]));
        const visibleEvents = events.length <= DISPLAY_EVENT_LIMIT
            ? events
            : Array.from({ length: DISPLAY_EVENT_LIMIT }, (_value, index) => events[(state.activityOffset + index) % events.length]);

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
            const participantNameElement = document.createElement("strong");
            const messageSuffix = document.createElement("span");
            const programTags = document.createElement("span");
            const participantName = typeof event.participant_name === "string" ? event.participant_name : "Peserta";
            const outcome = event?.outcome;
            const resultProgram = outcome?.kind === "decisive" ? resolveEventProgram(outcome.dominant_program) : null;
            const eventColor = programColor(resultProgram, "#ee3f9a");

            avatar.className = "monitor-avatar";
            row.classList.toggle("is-new", event.isNew === true);
            avatar.style.setProperty("--event-color", eventColor);

            participantNameElement.className = "monitor-activity-name";
            participantNameElement.style.setProperty("--event-color", eventColor);
            participantNameElement.textContent = participantName;

            messageSuffix.textContent = activityMessageSuffix(event);

            message.append(participantNameElement, messageSuffix);
            programTags.className = "monitor-activity-programs";
            const taggedPrograms = outcome?.kind === "tie"
                ? (Array.isArray(outcome.tied_programs) ? outcome.tied_programs : [])
                : (resultProgram ? [resultProgram] : []);
            taggedPrograms.forEach((taggedProgram) => {
                const tag = document.createElement("span");
                const code = typeof taggedProgram?.code === "string" && taggedProgram.code.trim() !== ""
                    ? taggedProgram.code.trim()
                    : "PROGRAM";
                tag.className = "monitor-activity-program";
                tag.style.setProperty("--event-color", eventProgramColor(taggedProgram, eventColor));
                tag.textContent = `${code} · ${programLabel(taggedProgram)}`;
                programTags.append(tag);
            });
            if (programTags.childElementCount === 0) {
                const tag = document.createElement("span");
                tag.className = "monitor-activity-program";
                tag.style.setProperty("--event-color", eventColor);
                tag.textContent = "HASIL";
                programTags.append(tag);
            }
            /* SMK_MATCH_PARTICIPANT_NAME_COLOR_V2 */
            const participantProgram = outcome?.kind === "tie"
                ? (
                    Array.isArray(outcome.tied_programs) && outcome.tied_programs.length > 0
                        ? outcome.tied_programs[0]
                        : null
                )
                : resultProgram;

            const participantNameColor = eventProgramColor(
                participantProgram,
                eventColor
            );

            const participantNameNode = message.querySelector(
                ".monitor-activity-name"
            );

            if (participantNameNode instanceof HTMLElement) {
                participantNameNode.style.setProperty(
                    "--event-color",
                    participantNameColor
                );

                participantNameNode.style.setProperty(
                    "color",
                    participantNameColor,
                    "important"
                );
            }
            row.append(avatar, message, programTags);

            return row;
        });

        activityList.replaceChildren(...rows);
    }

    function clearBatchState() {
        state.knownResultIds.clear();
        state.visibleEvents.clear();
        state.reconciliationActive = false;
        state.reconciliationAfterResultId = 0;
        state.activityOffset = 0;
        renderVisibleEvents();
    }

    function scheduleActivityRotation() {
        if (state.activityTimer !== null) {
            window.clearTimeout(state.activityTimer);
            state.activityTimer = null;
        }

        if (document.hidden || reducedMotion.matches || state.visibleEvents.size <= DISPLAY_EVENT_LIMIT) {
            return;
        }

        state.activityTimer = window.setTimeout(() => {
            state.activityOffset = (state.activityOffset + DISPLAY_EVENT_LIMIT) % state.visibleEvents.size;
            renderVisibleEvents();
            scheduleActivityRotation();
        }, ACTIVITY_ROTATION_MS);
    }

    function addRecentEvents(events) {
        if (!Array.isArray(events)) {
            return;
        }

        events.forEach((event) => {
            if (!event || !Number.isInteger(event.result_id) || event.result_id < 1) {
                return;
            }

            const isNew = !state.visibleEvents.has(event.result_id);
            state.knownResultIds.add(event.result_id);
            if (isNew && state.visibleEvents.size > 0) {
                event.isNew = true;
            }
            state.visibleEvents.set(event.result_id, event);
        });

        renderVisibleEvents();
        scheduleActivityRotation();
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
