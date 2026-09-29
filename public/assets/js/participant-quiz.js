(() => {
    'use strict';

    const root = document.getElementById('participant-quiz');
    const dataElement = document.getElementById('participant-quiz-data');

    if (!root || !dataElement) {
        return;
    }

    let quiz;
    try {
        quiz = JSON.parse(dataElement.textContent || '{}');
    } catch (error) {
        root.textContent = 'Kuis belum dapat dimuat. Silakan muat ulang halaman ini.';
        return;
    }

    const questions = Array.isArray(quiz.questions) ? quiz.questions : [];
    const state = {
        screen: 'LANDING',
        identity: {
            fullName: '',
            originSchool: '',
            className: '',
            whatsapp: '',
            marketingConsent: false,
        },
        answers: {},
        currentIndex: 0,
    };
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let analysisTimer = null;

    const element = (tagName, className, text) => {
        const node = document.createElement(tagName);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    };

    const button = (label, className = 'pq-button') => {
        const node = element('button', className, label);
        node.type = 'button';
        return node;
    };

    const screen = (extraClass = '') => element('section', `pq-screen ${extraClass}`.trim());

    const addDoodles = (container) => {
        ['pq-doodle--pink', 'pq-doodle--lime', 'pq-doodle--cyan'].forEach((className) => {
            const doodle = element('span', `pq-doodle ${className}`);
            doodle.setAttribute('aria-hidden', 'true');
            container.append(doodle);
        });
    };

    const setState = (nextState) => {
        if (analysisTimer !== null) {
            window.clearTimeout(analysisTimer);
            analysisTimer = null;
        }
        state.screen = nextState;
        render();
    };

    const renderLanding = () => {
        const view = screen('pq-screen--centered');
        addDoodles(view);

        view.append(element('span', 'pq-kicker pq-kicker--lime', `${questions.length} soal`));

        const heading = element('h1', 'pq-heading');
        heading.append('Jurusan apa yang ');
        heading.append(element('span', 'pq-heading__accent', 'cocok'));
        heading.append(' buat kamu?');
        view.append(heading);
        view.append(element('p', 'pq-copy', 'Jawab beberapa pertanyaan seru dan kenali gaya yang paling dekat denganmu.'));

        const art = element('div', 'pq-landing-art');
        const mascot = element('img', 'pq-mascot-all');
        mascot.src = '/assets/mascots/mascot-all.png';
        mascot.alt = 'Ilustrasi maskot SMK Match';
        art.append(mascot);
        view.append(art);

        const start = button('Mulai Main ✨');
        start.addEventListener('click', () => setState('IDENTITY'));
        view.append(start);
        return view;
    };

    const createField = (form, config) => {
        const wrapper = element('div', 'pq-field');
        const label = element('label', `pq-field-label ${config.labelClass}`, config.label);
        label.htmlFor = config.id;
        const input = element('input', 'pq-input');
        input.id = config.id;
        input.name = config.name;
        input.type = config.type || 'text';
        input.value = state.identity[config.name];
        input.placeholder = config.placeholder;
        input.autocomplete = config.autocomplete;
        input.required = true;
        input.setAttribute('aria-describedby', `${config.id}-error`);
        input.addEventListener('input', () => {
            state.identity[config.name] = input.value;
            input.removeAttribute('aria-invalid');
            const error = document.getElementById(`${config.id}-error`);
            if (error) {
                error.textContent = '';
            }
        });
        const error = element('p', 'pq-error');
        error.id = `${config.id}-error`;
        error.setAttribute('aria-live', 'polite');
        wrapper.append(label, input, error);
        form.append(wrapper);
    };

    const renderIdentity = () => {
        const view = screen();
        view.append(element('span', 'pq-kicker pq-kicker--pink', 'Langkah 1'));
        view.append(element('h1', 'pq-heading pq-heading--compact', 'Kenalan dulu, yuk! 👋'));
        view.append(element('p', 'pq-copy', 'Isi data singkatmu untuk memulai petualangan memilih jurusan.'));

        const card = element('div', 'pq-card pq-card--tilt-left');
        const form = element('form', 'pq-form');
        form.noValidate = true;
        createField(form, { id: 'full-name', name: 'fullName', label: 'Nama', labelClass: 'pq-field-label--pink', placeholder: 'Masukkan namamu', autocomplete: 'name' });
        createField(form, { id: 'origin-school', name: 'originSchool', label: 'Asal sekolah', labelClass: 'pq-field-label--lime', placeholder: 'SMP mana nih?', autocomplete: 'organization' });
        createField(form, { id: 'class-name', name: 'className', label: 'Kelas', labelClass: 'pq-field-label--cyan', placeholder: 'Contoh: IX-A', autocomplete: 'organization-title' });
        createField(form, { id: 'whatsapp', name: 'whatsapp', label: 'WhatsApp', labelClass: 'pq-field-label--pink', placeholder: 'Contoh: 0812 3456 7890', autocomplete: 'tel', type: 'tel' });

        const consent = element('label', 'pq-consent');
        const checkbox = element('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'marketingConsent';
        checkbox.checked = state.identity.marketingConsent;
        checkbox.addEventListener('change', () => {
            state.identity.marketingConsent = checkbox.checked;
        });
        consent.append(checkbox, document.createTextNode('Saya bersedia menerima informasi kegiatan sekolah melalui WhatsApp.'));
        form.append(consent);

        const actions = element('div', 'pq-button-row');
        const back = button('Kembali', 'pq-button pq-button--secondary');
        back.addEventListener('click', () => setState('LANDING'));
        const submit = button('Lanjut Main →');
        submit.type = 'submit';
        actions.append(back, submit);
        form.append(actions);

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const fields = [
                ['fullName', 'full-name', 'Nama perlu diisi.'],
                ['originSchool', 'origin-school', 'Asal sekolah perlu diisi.'],
                ['className', 'class-name', 'Kelas perlu diisi.'],
                ['whatsapp', 'whatsapp', 'Nomor WhatsApp perlu diisi.'],
            ];
            let firstInvalid = null;
            fields.forEach(([key, id, message]) => {
                const input = document.getElementById(id);
                const error = document.getElementById(`${id}-error`);
                if (typeof state.identity[key] !== 'string' || state.identity[key].trim() === '') {
                    input?.setAttribute('aria-invalid', 'true');
                    if (error) {
                        error.textContent = message;
                    }
                    firstInvalid ??= input;
                }
            });
            if (firstInvalid) {
                firstInvalid.focus();
                return;
            }
            setState('INTRO');
        });

        card.append(form);
        view.append(card);
        return view;
    };

    const renderIntro = () => {
        const view = screen('pq-screen--centered');
        addDoodles(view);
        const icon = element('div', 'pq-handoff-icon', '🎮');
        icon.setAttribute('aria-hidden', 'true');
        view.append(icon);
        view.append(element('h1', 'pq-heading pq-heading--compact', 'Siap bermain?'));
        view.append(element('p', 'pq-copy', 'Pilih jawaban yang paling terasa dekat dengan dirimu.'));

        const card = element('div', 'pq-card pq-card--tilt-right');
        card.append(element('span', 'pq-kicker pq-kicker--pink', 'Aturan main'));
        const rules = element('ul', 'pq-rules');
        [
            ['◎', 'Tidak ada jawaban benar atau salah.'],
            ['✦', 'Pilih yang paling menggambarkan dirimu.'],
            ['✓', 'Jawab semuanya dengan santai.'],
        ].forEach(([iconText, copy]) => {
            const item = element('li');
            item.append(element('span', 'pq-rule-icon', iconText), document.createTextNode(copy));
            rules.append(item);
        });
        card.append(rules);
        view.append(card);

        const actions = element('div', 'pq-button-row');
        const back = button('Kembali', 'pq-button pq-button--secondary');
        back.addEventListener('click', () => setState('IDENTITY'));
        const start = button('Siap, Mulai! 🚀');
        start.addEventListener('click', () => {
            state.currentIndex = 0;
            setState('QUESTION');
        });
        actions.append(back, start);
        view.append(actions);
        return view;
    };

    const renderQuestion = () => {
        const question = questions[state.currentIndex];
        if (!question) {
            return renderHandoff();
        }

        const isFinal = state.currentIndex === questions.length - 1;
        const view = screen();
        const progressHead = element('div', 'pq-progress-head');
        progressHead.append(
            element('span', 'pq-kicker pq-kicker--cyan', isFinal ? 'Final round!' : 'Quiz time'),
            element('span', '', `Soal ${state.currentIndex + 1} dari ${questions.length}`),
        );
        view.append(progressHead);

        const progress = element('div', 'pq-progress-track');
        progress.setAttribute('role', 'progressbar');
        progress.setAttribute('aria-label', 'Kemajuan kuis');
        progress.setAttribute('aria-valuemin', '1');
        progress.setAttribute('aria-valuemax', String(questions.length));
        progress.setAttribute('aria-valuenow', String(state.currentIndex + 1));
        progress.setAttribute('aria-valuetext', `Soal ${state.currentIndex + 1} dari ${questions.length}`);
        questions.forEach((unusedQuestion, index) => {
            const segment = element('span', 'pq-progress-segment');
            if (index < state.currentIndex) {
                segment.classList.add('is-complete');
            }
            if (index === state.currentIndex) {
                segment.classList.add('is-current');
            }
            segment.setAttribute('aria-hidden', 'true');
            progress.append(segment);
        });
        view.append(progress);

        if (isFinal) {
            view.append(element('p', 'pq-final-notice', '🔥 Final round! 🔥'));
        }

        const fieldset = element('fieldset', 'pq-options');
        const legend = element('legend', 'pq-question', question.text);
        fieldset.append(legend);

        question.options.forEach((option) => {
            const label = element('label', 'pq-option');
            const input = element('input');
            input.type = 'radio';
            input.name = `question-${question.id}`;
            input.value = option.id;
            input.checked = state.answers[question.id] === option.id;
            input.addEventListener('change', () => {
                state.answers[question.id] = option.id;
                next.disabled = false;
            });
            const checkMarker = element('span', 'pq-option__check', '✓');
            checkMarker.setAttribute('aria-hidden', 'true');
            label.append(input, element('span', '', option.text), checkMarker);
            fieldset.append(label);
        });
        view.append(fieldset);

        const actions = element('div', 'pq-button-row');
        const back = button('Kembali', 'pq-button pq-button--secondary');
        back.addEventListener('click', () => {
            if (state.currentIndex === 0) {
                setState('INTRO');
                return;
            }
            state.currentIndex -= 1;
            render();
        });
        const next = button(isFinal ? 'Lihat Hasil ✦' : 'Lanjut →');
        next.disabled = !state.answers[question.id];
        next.addEventListener('click', () => {
            if (!state.answers[question.id]) {
                return;
            }
            if (isFinal) {
                setState('ANALYZING');
                return;
            }
            state.currentIndex += 1;
            render();
        });
        actions.append(back, next);
        view.append(actions);
        return view;
    };

    const renderAnalyzing = () => {
        const view = screen('pq-screen--centered pq-analyzing');
        view.append(element('h1', 'pq-heading pq-heading--compact', 'Merangkai langkahmu...'));
        view.append(element('p', 'pq-copy', 'Sebentar ya, kami menyiapkan perjalanan berikutnya.'));
        const loader = element('div', 'pq-loader');
        loader.setAttribute('aria-label', 'Memproses langkah berikutnya');
        loader.setAttribute('role', 'status');
        loader.append(element('span', 'pq-loader__inner', '✦'));
        view.append(loader);
        const steps = element('ul', 'pq-analysis-steps');
        [
            'Merapikan jawabanmu...',
            'Menyiapkan langkah berikutnya...',
            'Hampir selesai...',
        ].forEach((copy, index) => {
            const item = element('li');
            item.append(element('span', `pq-analysis-dot${index === 2 ? ' pq-analysis-dot--active' : ''}`), document.createTextNode(copy));
            steps.append(item);
        });
        view.append(steps);

        analysisTimer = window.setTimeout(() => setState('RESULT_HANDOFF'), reducedMotion ? 0 : 1400);
        return view;
    };

    const renderHandoff = () => {
        const view = screen('pq-screen--centered');
        addDoodles(view);
        const icon = element('div', 'pq-handoff-icon', '✓');
        icon.setAttribute('aria-hidden', 'true');
        view.append(icon);
        view.append(element('h1', 'pq-heading pq-heading--compact', 'Kamu sudah selesai!'));
        view.append(element('p', 'pq-copy', 'Terima kasih sudah bermain. Langkah berikutnya sedang disiapkan untukmu.'));
        return view;
    };

    const render = () => {
        root.replaceChildren();
        root.dataset.state = state.screen;
        const views = {
            LANDING: renderLanding,
            IDENTITY: renderIdentity,
            INTRO: renderIntro,
            QUESTION: renderQuestion,
            ANALYZING: renderAnalyzing,
            RESULT_HANDOFF: renderHandoff,
        };
        root.append((views[state.screen] || renderLanding)());
    };

    render();
})();
