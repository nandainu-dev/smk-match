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

        mount.style.setProperty("--result-primary", resolvedMonitorProgramColor(theme));

        if (allowedColor.test(theme.accent_color || "")) {
            mount.style.setProperty("--result-accent", theme.accent_color);
        } else {
            mount.style.setProperty("--result-accent", neutralTheme.accent_color);
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

    function safeImagePath(path) {
        return typeof path === "string" && (/^\/assets\/[A-Za-z0-9][A-Za-z0-9._/-]*$/.test(path)
            || /^\/uploads\/programs\/[a-f0-9]{64}\.(?:jpg|jpeg|png|webp)$/.test(path));
    }

    function resultImagePath(profile) {
        return safeImagePath(profile?.result_image_path)
            ? profile.result_image_path
            : (safeImagePath(profile?.mascot_path) ? profile.mascot_path : "/assets/mascots/mascot-all.png");
    }

    function shareImagePath(profile) {
        return safeImagePath(profile?.share_image_path)
            ? profile.share_image_path
            : (safeImagePath(profile?.mascot_path) ? profile.mascot_path : "/assets/mascots/mascot-all.png");
    }

    function renderRanking(result) {
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
            const barColor = resolvedMonitorProgramColor(programProfile);

            fill.style.setProperty("--program-color", barColor);
            // Bind the immutable snapshot color to the actual painted element.
            // This keeps every row independent of inherited theme state.
            fill.style.setProperty("background-color", barColor, "important");

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
        const programCode = createElement("h1", "pr-code", profile.code || "");
        const primaryColor = resolvedMonitorProgramColor(profile);
        const primaryEntry = resultEntries().find((entry) => entry?.program === profile.code);

        programCode.style.setProperty("color", primaryColor, "important");
        programCode.style.setProperty("text-shadow", `0 0 10px ${primaryColor}, 0 0 24px ${primaryColor}, 0 6px 0 #2d1b69`, "important");
        mascotImage.src = resultImagePath(profile);
        mascotImage.alt = "Maskot program";
        mascot.append(mascotImage);
        card.append(
            createElement("p", "pr-badge", "Hasil terbuka"),
            createElement("p", "pr-personality", profile.personality_title || ""),
            programCode,
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
            createElement("h1", "pr-code", "HASILMU"),
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
            createElement("p", "pr-copy", typeof profile.description === "string" && profile.description.trim() !== ""
                ? profile.description
                : "Deskripsi program belum tersedia."),
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
                createElement("h1", "", "Hasil kecenderunganmu"),
                createElement("p", "", "Tidak ada program dominan yang dipilih."),
            );
        } else if (profile) {
            const mascot = createElement("img", "pr-mini-mascot");
            mascot.src = resultImagePath(profile);
            mascot.alt = "Maskot program";
            const text = createElement("div", "");
            text.append(
                createElement("h1", "", profileName(profile, "Program")),
                createElement("p", "", profile.personality_title || ""),
                createElement("p", "", participantName()),
            );
            header.append(mascot, text);
        } else {
            renderError();
            return;
        }

        content.append(renderRanking(result));

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

    function copyWithFallback(value) {
        const textarea = document.createElement("textarea");
        textarea.value = value;
        textarea.setAttribute("readonly", "");
        textarea.style.position = "fixed";
        textarea.style.opacity = "0";
        document.body.append(textarea);
        textarea.select();
        textarea.setSelectionRange(0, textarea.value.length);
        const copied = document.execCommand("copy");
        textarea.remove();

        return copied;
    }

    async function copyLink(feedback) {
        try {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
                await navigator.clipboard.writeText(window.location.href);
            } else if (!copyWithFallback(window.location.href)) {
                throw new Error("copy_failed");
            }
            feedback.textContent = "Tautan hasil sudah disalin. Tautan ini tetap mengikuti sesi hasilmu.";
        } catch (_error) {
            feedback.textContent = "Tautan belum dapat disalin. Silakan salin dari bilah alamat browser.";
        }
    }

    function shareCardFilename() {
        const normalizedName = participantName().toLocaleLowerCase("id-ID")
            .normalize("NFKD")
            .replace(/[^a-z0-9]+/g, "-")
            .replace(/^-+|-+$/g, "");

        return `hasil-smk-match-${normalizedName || "peserta"}.jpg`;
    }

    function colorValue(value, fallback) {
        return allowedColor.test(value || "") ? value : fallback;
    }

    function resolvedMonitorProgramColor(profile) {
        const candidate = typeof profile?.primary_color === "string" ? profile.primary_color.trim() : "";

        return colorValue(candidate, neutralTheme.primary_color);
    }

    function contrastTextColor(backgroundColor) {
        const normalized = colorValue(backgroundColor, neutralTheme.primary_color).slice(1);
        const red = Number.parseInt(normalized.slice(0, 2), 16);
        const green = Number.parseInt(normalized.slice(2, 4), 16);
        const blue = Number.parseInt(normalized.slice(4, 6), 16);
        const luminance = ((red * 299) + (green * 587) + (blue * 114)) / 1000;

        return luminance >= 150 ? "#2D1B69" : "#FFFFFF";
    }

    function applyShareCardColors(card, profile) {
        const primaryColor = resolvedMonitorProgramColor(profile);

        card.style.setProperty("--share-program-color", primaryColor);
        card.style.setProperty("--share-program-contrast", contrastTextColor(primaryColor));
    }

    function applyShareElementColors(programName, programCode, percentage, profile) {
        const primaryColor = resolvedMonitorProgramColor(profile);
        const contrastColor = contrastTextColor(primaryColor);

        programName.style.setProperty("color", primaryColor, "important");
        programCode.style.setProperty("border-color", primaryColor, "important");
        programCode.style.setProperty("background-color", primaryColor, "important");
        programCode.style.setProperty("color", contrastColor, "important");
        percentage.style.setProperty("background-color", primaryColor, "important");
        percentage.style.setProperty("color", contrastColor, "important");
    }

    function roundedRect(context, x, y, width, height, radius) {
        const safeRadius = Math.min(radius, width / 2, height / 2);
        context.beginPath();
        context.moveTo(x + safeRadius, y);
        context.arcTo(x + width, y, x + width, y + height, safeRadius);
        context.arcTo(x + width, y + height, x, y + height, safeRadius);
        context.arcTo(x, y + height, x, y, safeRadius);
        context.arcTo(x, y, x + width, y, safeRadius);
        context.closePath();
    }

    function loadShareImage(source) {
        return new Promise((resolve, reject) => {
            const image = new Image();
            image.decoding = "async";
            image.onload = () => resolve(image);
            image.onerror = () => reject(new Error("share_image_unavailable"));
            image.src = source;
        });
    }

    function drawCoverImage(context, image, x, y, width, height, radius) {
        const scale = Math.max(width / image.naturalWidth, height / image.naturalHeight);
        const renderedWidth = image.naturalWidth * scale;
        const renderedHeight = image.naturalHeight * scale;
        const imageX = x + (width - renderedWidth) / 2;
        const imageY = y + (height - renderedHeight) / 2;
        context.save();
        roundedRect(context, x, y, width, height, radius);
        context.clip();
        context.drawImage(image, imageX, imageY, renderedWidth, renderedHeight);
        context.restore();
    }

    function drawWrappedText(context, text, x, y, maxWidth, lineHeight, maxLines) {
        const words = String(text).split(/\s+/).filter(Boolean);
        const lines = [];
        let line = "";
        words.forEach((word) => {
            const candidate = line === "" ? word : `${line} ${word}`;
            if (context.measureText(candidate).width <= maxWidth || line === "") {
                line = candidate;
                return;
            }
            lines.push(line);
            line = word;
        });
        if (line !== "") {
            lines.push(line);
        }
        const visibleLines = lines.slice(0, maxLines);
        visibleLines.forEach((item, index) => context.fillText(item, x, y + (index * lineHeight)));
        return y + (visibleLines.length * lineHeight);
    }

    function canvasBlob(canvas) {
        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                if (blob) {
                    resolve(blob);
                    return;
                }
                reject(new Error("jpeg_generation_failed"));
            }, "image/jpeg", 0.92);
        });
    }

    async function createShareCardJpeg(profile, isTie) {
        if (document.fonts && document.fonts.ready) {
            await document.fonts.ready;
        }

        const canvas = document.createElement("canvas");
        const context = canvas.getContext("2d");
        if (!context) {
            throw new Error("canvas_unavailable");
        }

        const primary = resolvedMonitorProgramColor(profile);
        const accent = colorValue(profile?.accent_color, neutralTheme.accent_color);
        const width = 1080;
        const height = 1350;
        const padding = 72;
        const imageHeight = 500;
        const primaryEntry = resultEntries().find((entry) => entry?.program === profile?.code);
        const programName = isTie ? "Kekuatan setara" : profileName(profile, "Program");
        const percentage = isTie ? "Setara" : displayPercentage(primaryEntry?.percentage);
        const imageSource = isTie ? "/assets/mascots/mascot-all.png" : shareImagePath(profile);
        const image = await loadShareImage(imageSource);

        canvas.width = width;
        canvas.height = height;
        context.fillStyle = "#110a30";
        context.fillRect(0, 0, width, height);
        context.fillStyle = accent;
        context.beginPath();
        context.arc(width - 80, 100, 170, 0, Math.PI * 2);
        context.fill();
        context.fillStyle = primary;
        context.beginPath();
        context.arc(85, height - 100, 190, 0, Math.PI * 2);
        context.fill();
        roundedRect(context, padding, padding, width - (padding * 2), height - (padding * 2), 52);
        context.fillStyle = "#fff8f0";
        context.fill();

        context.fillStyle = "#2d1b69";
        context.font = '400 38px "Fredoka", sans-serif';
        context.fillText("SMK MATCH", padding + 48, padding + 76);
        context.fillStyle = primary;
        context.font = '400 58px "Fredoka", sans-serif';
        context.fillText(participantName(), padding + 48, padding + 152);

        drawCoverImage(context, image, padding + 48, padding + 205, width - (padding * 2) - 96, imageHeight, 38);
        let textY = padding + 790;
        context.fillStyle = "#475569";
        context.font = '400 34px "Fredoka", sans-serif';
        context.fillText("Jurusan yang cocok untuk anda :", padding + 48, textY);
        textY += 68;
        context.fillStyle = primary;
        context.font = '400 62px "Fredoka", sans-serif';
        textY = drawWrappedText(context, programName, padding + 48, textY, width - (padding * 2) - 96, 72, 2) + 24;
        const percentageWidth = Math.max(235, context.measureText(percentage).width + 78);
        const percentageY = textY + 22;
        roundedRect(context, padding + 48, percentageY, percentageWidth, 116, 42);
        context.fillStyle = primary;
        context.fill();
        context.fillStyle = contrastTextColor(primary);
        context.font = '400 76px "Fredoka", sans-serif';
        context.fillText(percentage, padding + 84, percentageY + 82);
        context.fillStyle = "#2d1b69";
        context.font = '400 28px "Fredoka", sans-serif';
        context.fillText("Temukan potensimu bersama SMK Match", padding + 48, height - padding - 46);

        return canvasBlob(canvas);
    }

    function downloadBlob(blob, filename) {
        const anchor = document.createElement("a");
        const objectUrl = URL.createObjectURL(blob);
        anchor.href = objectUrl;
        anchor.download = filename;
        document.body.append(anchor);
        anchor.click();
        anchor.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 0);
    }

    async function saveShareCard(profile, isTie, feedback) {
        try {
            const blob = await createShareCardJpeg(profile, isTie);
            downloadBlob(blob, shareCardFilename());
            feedback.textContent = "Kartu hasil JPG sedang diunduh.";
        } catch (_error) {
            feedback.textContent = "Kartu hasil belum dapat dibuat. Coba lagi setelah gambar selesai dimuat.";
        }
    }

    async function shareResult(profile, isTie, feedback) {
        if (window.isSecureContext !== true) {
            feedback.textContent = "Bagikan langsung belum tersedia pada koneksi HTTP lokal. Coba melalui versi HTTPS. Gunakan Simpan kartu jika ingin menyimpan JPG.";
            return;
        }

        try {
            const blob = await createShareCardJpeg(profile, isTie);
            const text = shareText(profile, isTie);
            if (typeof navigator.share !== "function") {
                feedback.textContent = "Bagikan langsung belum didukung browser ini. Gunakan Simpan kartu untuk menyimpan JPG.";
                return;
            }

            if (typeof File === "function") {
                const file = new File([blob], shareCardFilename(), { type: "image/jpeg" });
                if (typeof navigator.canShare !== "function" || navigator.canShare({ files: [file] })) {
                    await navigator.share({ files: [file], title: "Hasil SMK Match", text });
                    feedback.textContent = "Kartu hasil siap dibagikan.";
                    return;
                }
            }
            await navigator.share({ title: "Hasil SMK Match", text });
            feedback.textContent = "Hasil siap dibagikan.";
        } catch (error) {
            feedback.textContent = error?.name === "AbortError"
                ? "Berbagi dibatalkan."
                : "Hasil belum dapat dibagikan. Coba lagi setelah gambar selesai dimuat.";
        }
    }

    function shareImageFrame(source, alt) {
        const frame = createElement("div", "pr-share-image-frame");
        const image = createElement("img", "");
        image.src = source;
        image.alt = alt;
        frame.append(image);
        return frame;
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
        applyShareCardColors(card, isTie ? null : profile);

        if (isTie) {
            card.append(
                shareImageFrame("/assets/mascots/mascot-all.png", "Maskot SMK Match"),
                createElement("p", "pr-share-name", participantName()),
                createElement("p", "pr-share-label", "Jurusan yang cocok untuk anda :"),
                createElement("h1", "pr-share-program", "Kekuatan setara"),
                createElement("p", "pr-share-percent", "Setara"),
            );
        } else if (profile) {
            const primaryEntry = resultEntries().find((entry) => entry?.program === profile.code);
            const programName = createElement("h1", "pr-share-program", profileName(profile, "Program"));
            const programCode = createElement("p", "pr-share-code", profile.code || "");
            const percentage = createElement("p", "pr-share-percent", displayPercentage(primaryEntry?.percentage));

            card.append(
                shareImageFrame(shareImagePath(profile), "Gambar program"),
                createElement("p", "pr-share-name", participantName()),
                createElement("p", "pr-share-label", "Jurusan yang cocok untuk anda :"),
                programName,
                programCode,
                percentage,
            );
            applyShareElementColors(programName, programCode, percentage, profile);
        } else {
            renderError();
            return;
        }

        actions.append(
            createButton("Bagikan hasil", "pr-button--primary", () => shareResult(profile, isTie, feedback)),
            createButton("Simpan kartu", "pr-button--outline", () => saveShareCard(profile, isTie, feedback)),
            createButton("Salin tautan", "pr-button--outline", () => copyLink(feedback)),
        );

        share.append(
            createElement("p", "pr-eyebrow", "Bagikan hasilmu"),
            card,
            actions,
            feedback,
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
        const result = resultData();
        const profile = primaryProfile();

        mascot.src = result?.is_tie === true ? "/assets/mascots/mascot-all.png" : resultImagePath(profile);
        mascot.alt = result?.is_tie === true ? "Maskot SMK Match" : "Maskot program";
        actions.append(
            createButton("Kenali Jurusan Lebih Dalam", "pr-button--primary", () => renderDetail()),
            createButton("Bagikan Hasil Lagi", "pr-button--outline", () => renderShare()),
        );
        card.append(
            createElement("p", "pr-badge", "100% selesai"),
            createElement("div", "pr-medal", "🏆"),
            createElement("h1", "pr-finish-title", "Mission Complete"),
            mascot,
            createElement("p", "", `Terima kasih, ${participantName()}!`),
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
