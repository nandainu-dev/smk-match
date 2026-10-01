(() => {
    "use strict";

    document.querySelectorAll("[data-admin-qr-target]").forEach((card) => {
        if (!(card instanceof HTMLElement)) {
            return;
        }

        const target = card.dataset.adminQrTarget;
        const preview = card.querySelector("[data-admin-qr-preview]");
        const download = card.querySelector("[data-admin-qr-download]");
        if (typeof target !== "string" || target === "" || !(preview instanceof HTMLElement)) {
            return;
        }

        try {
            window.SmkMatchQrSvg.render(preview, target, { foreground: "#25135d", background: "#ffffff" });
        } catch (_error) {
            preview.textContent = "QR tidak tersedia.";
            return;
        }

        if (!(download instanceof HTMLButtonElement)) {
            return;
        }

        download.addEventListener("click", () => {
            const svg = preview.querySelector("svg");
            if (!(svg instanceof SVGElement)) {
                return;
            }

            const blob = new Blob([svg.outerHTML], { type: "image/svg+xml;charset=utf-8" });
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.href = url;
            link.download = "smk-match-" + target.split("/").pop() + ".svg";
            link.click();
            URL.revokeObjectURL(url);
        });
    });
})();
