(() => {
    "use strict";

    const mount = document.getElementById("participant-result");
    const dataNode = document.getElementById("participant-result-data");

    if (!(mount instanceof HTMLElement) || !(dataNode instanceof HTMLScriptElement)) {
        return;
    }

    let presentation;

    try {
        presentation = JSON.parse(dataNode.textContent || "{}");
    } catch (_error) {
        presentation = { status: "error", error: { message: "Hasil belum dapat ditampilkan." } };
    }

    const neutralTheme = {
        primary_color: "#312E81",
        accent_color: "#7C3AED",
    };

    const allowedColor = /^#[0-9a-f]{6}$/i;

    function createElement(tagName, className, text) {
        const element = document.createElement(tagName);

        if (className) {
            element.className = className;
        }

        if (typeof text === "string") {
            element.textContent = text;
        }

        return element;
    }

    function createButton(label, className, handler, disabled = false) {
        const button = createElement("button", `pr-button ${className}`, label);
        button.type = "button";
        button.disabled = disabled;

        if (!disabled) {
            button.addEventListener("click", handler);
        }

        return button;
    }

    function profileMap() {
        const profiles = presentation.presentation?.profiles;

        return profiles && typeof profiles === "object" ? profiles : {};
    }

    function profileFor(programCode) {
        const profile = profileMap()[programCode];

        return profile && typeof profile === "object" ? profile : null;
    }

    function primaryProfile() {
        const profile = presentation.presentation?.primary_profile;

        return profile && typeof profile === "object" ? profile : null;
    }

    function resultData() {
        const result = presentation.result;

        return result && typeof result === "object" ? result : null;
    }

    function resultEntries() {
        const ranking = resultData()?.ranking;

        return Array.isArray(ranking) ? ranking : [];
    }

    function applyTheme(profile) {
        const theme = profile || neutralTheme;

        if (allowedColor.test(theme.primary_color || "")) {
            mount.style.setProperty("--result-primary", theme.primary_color);
        }

        if (allowedColor.test(theme.accent_color || "")) {
            mount.style.setProperty("--result-accent", theme.accent_color);
        }
    }

    function displayPercentage(value) {
        return Number.isFinite(Number(value)) ? `${String(value)}%` : "Tidak tersedia";
    }

    function visualPercentage(value) {
        const numericValue = Number(value);

        if (!Number.isFinite(numericValue)) {
            return 0;
        }

        return Math.min(100, Math.max(0, numericValue));
    }

    function participantName() {
        const name = presentation.participant?.display_name;

        return typeof name === "string" && name.trim() !== "" ? name : "Sahabat SMK";
    }

    function screen(className) {
        const element = createElement("section", `pr-screen ${className}`);
        element.tabIndex = -1;

        return element;
    }

    function actionRow() {
        return createElement("div", "pr-actions");
    }

    function profileName(profile, fallbackCode) {
        return typeof profile?.display_name === "string" ? profile.display_name : fallbackCode;
    }

    function renderRanking(result, themeProfile) {
        const panel = createElement("section", "pr-panel");
        const title = createElement("h2", "pr-section-title", "Skor Kecenderunganmu");
        const ranking = createElement("div", "pr-ranking");

        panel.append(title);

        resultEntries().forEach((entry) => {
            const programCode = typeof entry?.program === "string" ? entry.program : "Program";
            const programProfile = profileFor(programCode);
            const row = createElement("div", "pr-ranking-row");
            const label = createElement("div", "pr-ranking-label");
            const labelText = createElement("span", "", `${profileName(programProfile, programCode)} (${programCode})`);
            const percentage = createElement("strong", "", displayPercentage(entry?.percentage));
            const meter = createElement("div", "pr-meter");
            const fill = createElement("span", "");
            const barColor = programProfile?.primary_color || themeProfile?.primary_color;

            if (allowedColor.test(barColor || "")) {
                fill.style.setProperty("--program-color", barColor);
            }

            fill.style.width = `${visualPercentage(entry?.percentage)}%`;
            label.append(labelText, percentage);
            meter.append(fill);
            row.append(label, meter);
            ranking.append(row);
        });

        panel.append(ranking);

        return panel;
    }

    function renderNormalReveal() {
        const profile = primaryProfile();

        if (!profile) {
            renderError();
            return;
        }

        const reveal = screen("pr-reveal");
        const card = createElement("div", "pr-reveal-card");
        const mascot = createElement("div", "pr-mascot-orb");
        const mascotImage = createElement("img", "");
        const actions = actionRow();
        const primaryEntry = resultEntries().find((entry) => entry?.program === profile.code);

        mascotImage.src = typeof profile.mascot_path === "string" ? profile.mascot_path : "/assets/mascots/mascot-all.png";
        mascotImage.alt = "Maskot program";
        mascot.append(mascotImage);
        card.append(
            createElement("p", "pr-badge", "Hasil terbuka"),
            createElement("p", "pr-personality", profile.personality_title || ""),
            createElement("h1", "pr-code", profile.code || ""),
            createElement("p", "pr-program-name", profileName(profile, "Program")),
            mascot,
            createElement("p", "pr-participant", `Selamat, ${participantName()}!`),
            createElement("p", "pr-tagline", profile.tagline || ""),
            createElement("p", "pr-tagline", `Kecenderungan tampilan: ${displayPercentage(primaryEntry?.percentage)}`),
        );
        actions.append(createButton("Lihat detail", "pr-button--primary", () => renderDetail()));
        card.append(actions);
        reveal.append(card);
        mount.replaceChildren(reveal);
        reveal.focus();
    }

    function renderTieReveal() {
        const result = resultData();
        const reveal = screen("pr-reveal");
        const card = createElement("div", "pr-reveal-card");
        const mascot = createElement("div", "pr-mascot-orb");
        const mascotImage = createElement("img", "");
        const tieList = createElement("ul", "pr-tie-list");
        const actions = actionRow();
        const tiedPrograms = Array.isArray(result?.tied_programs) ? result.tied_programs : [];

        mascotImage.src = "/assets/mascots/mascot-all.png";
        mascotImage.alt = "Maskot SMK Match";
        mascot.append(mascotImage);

        tiedPrograms.forEach((programCode) => {
            const profile = profileFor(programCode);
            tieList.append(createElement("li", "", profileName(profile, String(programCode))));
        });

        card.append(
            createElement("p", "pr-badge", "Hasil terbuka"),
            createElement("p", "pr-personality", "KEKUATANMU SEIMBANG"),
            createElement("h1", "pr-code", "SETARA"),
            mascot,
            createElement("p", "pr-participant", `Hebat, ${participantName()}!`),
            createElement("p", "pr-tagline", "Lebih dari satu kecenderungan tampil sama kuat. Tidak ada satu program yang dipilih sebagai pemenang."),
            tieList,
        );
        actions.append(createButton("Lihat detail", "pr-button--primary", () => renderDetail()));
        card.append(actions);
        reveal.append(card);
        mount.replaceChildren(reveal);
        reveal.focus();
    }

    function renderProfileDetail(profile) {
        const fragment = document.createDocumentFragment();
        const about = createElement("section", "pr-panel");
        const superpower = createElement("section", "pr-panel pr-superpower");
        const skills = createElement("section", "pr-panel");
        const careers = createElement("section", "pr-panel");
        const programInfo = createElement("section", "pr-panel");
        const skillList = createElement("ul", "pr-chip-list");
        const careerList = createElement("ul", "pr-career-list");

        about.append(
            createElement("h2", "pr-section-title", "Tentang Kamu"),
            createElement("p", "pr-copy", profile.description || ""),
        );
        superpower.append(
            createElement("h2", "pr-section-title", "Superpower"),
            createElement("p", "", profile.superpower || ""),
        );

        (Array.isArray(profile.skills) ? profile.skills : []).forEach((skill) => {
            skillList.append(createElement("li", "", String(skill)));
        });
        skills.append(createElement("h2", "pr-section-title", "Hal yang bisa kamu eksplor"), skillList);

        (Array.isArray(profile.careers) ? profile.careers : []).forEach((career) => {
            careerList.append(createElement("li", "", String(career)));
        });
        careers.append(createElement("h2", "pr-section-title", "Contoh arah karier"), careerList);

        programInfo.append(
            createElement("h2", "pr-section-title", "Mengenal program"),
            createElement("p", "pr-copy", "Gunakan hasil ini sebagai titik awal untuk mencari tahu kegiatan belajar dan karya yang bisa kamu eksplor bersama sekolah."),
        );

        fragment.append(about, superpower, skills, careers, programInfo);

        return fragment;
    }

    function renderTieDetails(content, result) {
        const infoPanel = createElement("section", "pr-panel");
        const tiedPrograms = Array.isArray(result.tied_programs) ? result.tied_programs : [];
        const grid = createElement("div", "pr-tie-profile-grid");

        infoPanel.append(
            createElement("h2", "pr-section-title", "Kekuatan yang sama kuat"),
            createElement("p", "pr-copy", "Kamu dapat mengeksplorasi beberapa arah sekaligus. Hasil setara ini tidak menetapkan satu pemenang."),
        );
        content.append(infoPanel);

        tiedPrograms.forEach((programCode) => {
            const profile = profileFor(programCode);

            if (!profile) {
                return;
            }

            const card = createElement("article", "pr-tie-profile");
            card.append(
                createElement("h3", "", profileName(profile, String(programCode))),
                createElement("p", "", profile.tagline || ""),
            );
            grid.append(card);
        });

        content.append(grid);
    }

    function renderDetail() {
        const result = resultData();

        if (!result) {
            renderError();
            return;
        }

        const isTie = result.is_tie === true;
        const profile = primaryProfile();
        const detail = screen("pr-detail");
        const header = createElement("header", `pr-detail-head${isTie ? " pr-detail-head--tie" : ""}`);
        const content = createElement("div", "");
        const actions = actionRow();

        if (isTie) {
            header.append(
                createElement("h1", "", "Hasilmu seimbang"),
                createElement("p", "", "Tidak ada program dominan yang dipilih."),
            );
        } else if (profile) {
            const mascot = createElement("img", "pr-mini-mascot");
            mascot.src = typeof profile.mascot_path === "string" ? profile.mascot_path : "/assets/mascots/mascot-all.png";
            mascot.alt = "Maskot program";
            const text = createElement("div", "");
            text.append(
                createElement("h1", "", profileName(profile, "Program")),
                createElement("p", "", profile.personality_title || ""),
            );
            header.append(mascot, text);
        } else {
            renderError();
            return;
        }

        content.append(renderRanking(result, profile || neutralTheme));

        if (isTie) {
            renderTieDetails(content, result);
        } else if (profile) {
            content.append(renderProfileDetail(profile));
        }

        actions.append(
            createButton("Bagikan hasil", "pr-button--primary", () => renderShare()),
            createButton("Selesai", "pr-button--lime", () => renderFinish()),
        );
        detail.append(header, content, actions);
        mount.replaceChildren(detail);
        detail.focus();
    }

    function shareText(profile, isTie) {
        if (isTie) {
            return "Aku menemukan beberapa kecenderungan yang sama kuat di SMK Match!";
        }

        return typeof profile?.share_headline === "string" ? profile.share_headline : "Aku mencoba SMK Match!";
    }

    function copyLink(feedback) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
            navigator.clipboard.writeText(window.location.href)
                .then(() => {
                    feedback.textContent = "Tautan hasil sudah disalin.";
                })
                .catch(() => {
                    feedback.textContent = "Tautan tidak dapat disalin. Salin dari bilah alamat browser.";
                });
            return;
        }

        feedback.textContent = "Salin tautan dari bilah alamat browser.";
    }

    function renderShare() {
        const result = resultData();

        if (!result) {
            renderError();
            return;
        }

        const isTie = result.is_tie === true;
        const profile = primaryProfile();
        const share = screen("pr-share");
        const card = createElement("article", "pr-share-card");
        const actions = actionRow();
        const feedback = createElement("p", "pr-share-feedback");
        feedback.setAttribute("role", "status");
        feedback.setAttribute("aria-live", "polite");

        if (isTie) {
            const image = createElement("img", "");
            image.src = "/assets/mascots/mascot-all.png";
            image.alt = "Maskot SMK Match";
            card.append(
                image,
                createElement("p", "pr-eyebrow", "SMK Match"),
                createElement("h1", "pr-share-code", "SETARA"),
                createElement("p", "", "Kekuatanmu hadir dalam lebih dari satu arah."),
            );
        } else if (profile) {
            const image = createElement("img", "");
            const primaryEntry = resultEntries().find((entry) => entry?.program === profile.code);
            image.src = typeof profile.mascot_path === "string" ? profile.mascot_path : "/assets/mascots/mascot-all.png";
            image.alt = "Maskot program";
            card.append(
                image,
                createElement("p", "pr-eyebrow", participantName()),
                createElement("h1", "pr-share-code", profile.code || ""),
                createElement("p", "", profile.personality_title || ""),
                createElement("p", "pr-share-percent", displayPercentage(primaryEntry?.percentage)),
            );
        } else {
            renderError();
            return;
        }

        if (typeof navigator.share === "function") {
            actions.append(createButton("Bagikan", "pr-button--primary", () => {
                navigator.share({
                    title: "SMK Match",
                    text: shareText(profile, isTie),
                    url: window.location.href,
                }).catch(() => {
                    feedback.textContent = "Berbagi dibatalkan atau belum tersedia.";
                });
            }));
        }

        actions.append(
            createButton("Salin tautan", "pr-button--outline", () => copyLink(feedback)),
            createButton("Simpan kartu", "pr-button--outline", () => {}, true),
        );

        share.append(
            createElement("p", "pr-eyebrow", "Bagikan hasilmu"),
            card,
            actions,
            feedback,
            createElement("p", "pr-share-note", "Simpan kartu belum tersedia pada versi ini."),
            createButton("Selesai", "pr-button--lime", () => renderFinish()),
        );
        mount.replaceChildren(share);
        share.focus();
    }

    function renderFinish() {
        const finish = screen("pr-finish");
        const card = createElement("div", "pr-finish-card");
        const mascot = createElement("img", "");
        const actions = actionRow();

        mascot.src = "/assets/mascots/mascot-all.png";
        mascot.alt = "Maskot SMK Match";
        actions.append(
            createButton("Lihat detail", "pr-button--primary", () => renderDetail()),
            createButton("Bagikan hasil", "pr-button--outline", () => renderShare()),
        );
        card.append(
            createElement("p", "pr-badge", "100% selesai"),
            createElement("div", "pr-medal", "🏆"),
            createElement("h1", "pr-finish-title", "Mission Complete"),
            mascot,
            createElement("p", "", "Terima kasih sudah menjelajahi kecenderunganmu bersama SMK Match."),
            actions,
        );
        finish.append(card);
        mount.replaceChildren(finish);
        finish.focus();
    }

    function renderError() {
        const error = screen("pr-error");
        const card = createElement("div", "pr-error-card");
        const message = presentation.error?.message;
        const actions = actionRow();

        card.append(
            createElement("p", "pr-badge", "Hasil belum siap"),
            createElement("h1", "", "Belum bisa menampilkan hasil"),
            createElement("p", "pr-error-copy", typeof message === "string" ? message : "Silakan coba lagi nanti."),
        );
        actions.append(createButton("Kembali ke hasil", "pr-button--lime", () => renderReveal()));
        card.append(actions);
        error.append(card);
        mount.replaceChildren(error);
        error.focus();
    }

    function renderReveal() {
        const result = resultData();

        if (presentation.status !== "success" || !result) {
            renderError();
            return;
        }

        applyTheme(result.is_tie === true ? neutralTheme : primaryProfile());

        if (result.is_tie === true) {
            renderTieReveal();
            return;
        }

        renderNormalReveal();
    }

    renderReveal();
})();
