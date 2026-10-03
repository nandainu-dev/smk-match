(() => {
    "use strict";

    const list = document.getElementById("admin-question-list");
    const add = document.getElementById("admin-add-question");
    const template = document.getElementById("admin-question-template");
    const programs = Array.isArray(window.SMK_MATCH_AUTHORING_PROGRAMS) ? window.SMK_MATCH_AUTHORING_PROGRAMS : [];
    if (!(list instanceof HTMLElement) || !(add instanceof HTMLButtonElement) || !(template instanceof HTMLTemplateElement)) return;

    const option = (questionIndex, optionIndex) => {
        const letter = String.fromCharCode(65 + optionIndex);
        const fieldset = document.createElement("fieldset");
        fieldset.className = "admin-option-row";
        const legend = document.createElement("legend");
        legend.textContent = `Pilihan ${letter}`;
        fieldset.append(legend);

        const label = document.createElement("label");
        label.className = "admin-field admin-grid-wide";
        const labelText = document.createElement("span");
        labelText.textContent = "Jawaban";
        const text = document.createElement("input");
        text.required = true;
        text.value = `Pilihan ${letter}`;
        text.name = `questions[${questionIndex}][options][${optionIndex}][text]`;
        label.append(labelText, text);
        fieldset.append(label);

        const order = document.createElement("input");
        order.type = "hidden";
        order.name = `questions[${questionIndex}][options][${optionIndex}][order]`;
        order.value = String((optionIndex + 1) * 10);
        fieldset.append(order);

        const direction = document.createElement("label");
        direction.className = "admin-field";
        const directionText = document.createElement("span");
        directionText.textContent = "Jurusan";
        const select = document.createElement("select");
        select.required = true;
        select.name = `questions[${questionIndex}][options][${optionIndex}][primary_program]`;
        select.add(new Option("Pilih jurusan", ""));
        programs.forEach((code) => select.add(new Option(code, code)));
        direction.append(directionText, select);
        fieldset.append(direction);

        return fieldset;
    };

    const renumber = () => {
        Array.from(list.children).forEach((card, questionIndex) => {
            const number = card.querySelector("[data-question-number]");
            if (number instanceof HTMLElement) number.textContent = String(questionIndex + 1);
            card.querySelectorAll("[name]").forEach((field) => {
                field.name = field.name.replace(/questions\[\d+]/, `questions[${questionIndex}]`);
                field.name = field.name.replace(/question_image_\d+/, `question_image_${questionIndex}`);
            });
        });
    };

    const attachDelete = (card) => {
        const remove = card.querySelector("[data-delete-question]");
        if (remove instanceof HTMLButtonElement) {
            remove.addEventListener("click", () => {
                card.remove();
                renumber();
            });
        }
    };

    const addQuestion = () => {
        const questionIndex = list.children.length;
        const fragment = template.content.cloneNode(true);
        const card = fragment.firstElementChild;
        if (!(card instanceof HTMLElement)) return;
        card.querySelectorAll("[data-name]").forEach((input) => {
            input.name = `questions[${questionIndex}][${input.dataset.name}]`;
        });
        const order = card.querySelector('[data-name="order"]');
        if (order instanceof HTMLInputElement) order.value = String((questionIndex + 1) * 10);
        const options = card.querySelector("[data-options]");
        if (!(options instanceof HTMLElement)) return;
        for (let optionIndex = 0; optionIndex < 4; optionIndex += 1) options.append(option(questionIndex, optionIndex));
        attachDelete(card);
        list.append(card);
        renumber();
    };
    Array.from(list.children).forEach((card, questionIndex) => {
        const options = card.querySelector("[data-options]");
        if (!(options instanceof HTMLElement)) return;
        const existingOptions = options.querySelectorAll("fieldset.admin-option-row").length;
        for (let optionIndex = existingOptions; optionIndex < 4; optionIndex += 1) options.append(option(questionIndex, optionIndex));
    });
    add.addEventListener("click", addQuestion);
})();
