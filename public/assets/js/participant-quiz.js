(() => {
    'use strict';

    const root = document.getElementById('participant-quiz');
    const dataElement = document.getElementById('participant-quiz-data');

    if (!root || !dataElement) {
        return;
    }

    let config;
    try {
        config = JSON.parse(dataElement.textContent || '{}');
    } catch (error) {
        root.textContent = 'Kuis belum dapat dimuat. Silakan muat ulang halaman ini.';
        return;
    }

    const realMode = config.mode === 'real';
    const alias = typeof config.alias === 'string' ? config.alias : '';
    const configuredQuestionCount = Number.isInteger(config.question_count) && config.question_count > 0
        ? config.question_count
        : null;
    const presentationPrograms = Array.isArray(config.presentation_programs) ? config.presentation_programs : [];
    let questions = realMode ? [] : (Array.isArray(config.questions) ? config.questions : []);
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
        attemptUuid: null,
        submissionInFlight: false,
        errorMessage: '',
        retry: null,
        canStartNewSession: false,
    };
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let analysisTimer = null;
    const request = window.fetch.bind(window);

    const isUuidV4 = (value) => typeof value === 'string'
        && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);

    const storageKey = () => `smk_match_attempt:${alias}`;

    const storedAttemptUuid = () => {
        try {
            const value = window.sessionStorage.getItem(storageKey());
            if (value !== null && !isUuidV4(value)) {
                window.sessionStorage.removeItem(storageKey());
                return null;
            }
            return value;
        } catch (error) {
            return null;
        }
    };

    const rememberAttemptUuid = (attemptUuid) => {
        try {
            window.sessionStorage.setItem(storageKey(), attemptUuid);
        } catch (error) {
            // The current in-memory attempt UUID remains usable.
        }
    };

    const forgetAttemptUuid = () => {
        try {
            window.sessionStorage.removeItem(storageKey());
        } catch (error) {
            // Storage can be unavailable without preventing a new in-memory session.
        }
    };

    const generateUuidV4 = () => {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            const bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
            return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
        }
        return null;
    };

    const optionalValue = (value) => typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
    const validWhatsapp = (value) => {
        const phone = typeof value === 'string' ? value.trim() : '';

        if (!/^08[0-9]{8,11}$/.test(phone) || /^([0-9])\1+$/.test(phone)) {
            return false;
        }

        const subscriberNumber = phone.slice(2);
        return !subscriberNumber.includes('123456789')
            && !subscriberNumber.includes('987654321')
            && !/^([0-9]{2})(?:\1){3,4}$/.test(subscriberNumber);
    };
    const validQuestionImagePath = (value) => typeof value === 'string'
        && /^\/uploads\/questions\/[a-f0-9]{64}\.(?:jpg|jpeg|png|webp)$/.test(value);
    const validPresentationImagePath = (value) => typeof value === 'string'
        && (/^\/assets\/[A-Za-z0-9][A-Za-z0-9._/-]*$/.test(value)
            || /^\/uploads\/programs\/[a-f0-9]{64}\.(?:jpg|jpeg|png|webp)$/.test(value));
    const allowedColor = (value) => typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value);

    const showError = (message, retry = null, canStartNewSession = false) => {
        state.errorMessage = message;
        state.retry = retry;
        state.canStartNewSession = canStartNewSession;
        setState('ERROR');
    };

    const validQuiz = (payload, attemptUuid) => {
        if (!payload || payload.ok !== true || payload.attempt_uuid !== attemptUuid || payload.status !== 'started'
            || !payload.quiz || typeof payload.quiz.name !== 'string' || !Array.isArray(payload.quiz.questions)) {
            return false;
        }
        return payload.quiz.questions.every((question) => question && typeof question.id === 'string'
            && typeof question.text === 'string' && Number.isInteger(question.order) && Array.isArray(question.options)
            && (question.help_text === undefined || typeof question.help_text === 'string')
            && (question.image_path === undefined || validQuestionImagePath(question.image_path))
            && question.options.every((option) => option && typeof option.id === 'string'
                && typeof option.text === 'string' && Number.isInteger(option.order)));
    };

    const hasCompleteAnswers = () => questions.length > 0
        && questions.every((question) => typeof state.answers[question.id] === 'string'
            && state.answers[question.id].trim() !== '');

    const validSubmission = (response, payload, attemptUuid) => {
        if ((response.status !== 201 && response.status !== 200) || !payload || payload.ok !== true
            || payload.attempt_uuid !== attemptUuid || payload.status !== 'completed'
            || typeof payload.already_completed !== 'boolean') {
            return false;
        }

        return (response.status === 201 && payload.already_completed === false)
            || (response.status === 200 && payload.already_completed === true);
    };

    const submissionError = (message, retry = null, canStartNewSession = false) => {
        state.submissionInFlight = false;
        showError(message, retry, canStartNewSession);
    };

    const submitRealAnswers = async () => {
        if (!realMode || state.submissionInFlight) {
            return;
        }

        const attemptUuid = state.attemptUuid;
        if (!isUuidV4(attemptUuid)) {
            submissionError('Sesi kuis tidak tersedia. Silakan mulai kembali dari link yang diberikan.', null, true);
            return;
        }

        if (!hasCompleteAnswers()) {
            const firstUnansweredIndex = questions.findIndex((question) => !state.answers[question.id]);
            submissionError('Jawaban belum lengkap. Silakan lengkapi semua jawabanmu.', () => {
                state.currentIndex = Math.max(firstUnansweredIndex, 0);
                setState('QUESTION');
            });
            return;
        }

        const answers = questions.map((question) => ({
            question_id: question.id,
            option_id: state.answers[question.id],
        }));
        state.submissionInFlight = true;
        setState('SUBMITTING');

        try {
            const response = await request(`/api/public/submit/${encodeURIComponent(attemptUuid)}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ answers }),
            });
            let payload = null;
            try {
                payload = await response.json();
            } catch (error) {
                // An invalid response must not move the participant to the handoff.
            }

            if (validSubmission(response, payload, attemptUuid)) {
                state.submissionInFlight = false;
                setState('ANALYZING');
                return;
            }

            if (response.status === 404) {
                submissionError('Sesi kuis tidak tersedia. Silakan mulai kembali dari link yang diberikan.', null, true);
            } else if (response.status === 422) {
                submissionError('Jawaban belum dapat diproses. Silakan periksa kembali jawabanmu.', () => {
                    state.currentIndex = questions.length - 1;
                    setState('QUESTION');
                });
            } else if (response.status === 400 || response.status === 415) {
                submissionError('Permintaan belum dapat diproses. Silakan coba lagi nanti.');
            } else {
                submissionError('Terjadi gangguan sementara. Silakan coba lagi.', submitRealAnswers);
            }
        } catch (error) {
            submissionError('Terjadi gangguan sementara. Silakan coba lagi.', submitRealAnswers);
        }
    };

    const loadRealQuiz = async (attemptUuid) => {
        setState('LOADING_QUIZ');
        try {
            const response = await fetch(`/api/public/quiz/${encodeURIComponent(attemptUuid)}`, { credentials: 'same-origin' });
            let payload = null;
            try {
                payload = await response.json();
            } catch (error) {
                // A malformed response is handled as a safe temporary failure.
            }
            if (response.status === 409 && payload && payload.error === 'attempt_completed') {
                showError('Sesi ini sudah selesai.');
                return;
            }
            if (response.status === 404) {
                showError('Sesi kuis tidak tersedia. Silakan mulai kembali dari link yang diberikan.');
                return;
            }
            if (response.status !== 200 || !validQuiz(payload, attemptUuid)) {
                showError('Terjadi gangguan sementara. Silakan coba lagi.', () => loadRealQuiz(attemptUuid));
                return;
            }
            questions = payload.quiz.questions;
            state.answers = {};
            state.currentIndex = 0;
            setState('INTRO');
        } catch (error) {
            showError('Terjadi gangguan sementara. Silakan coba lagi.', () => loadRealQuiz(attemptUuid));
        }
    };

    const startRealQuiz = async () => {
        const attemptUuid = state.attemptUuid || storedAttemptUuid() || generateUuidV4();
        if (!isUuidV4(attemptUuid)) {
            showError('Browser ini belum mendukung pembuatan sesi yang aman.');
            return;
        }
        state.attemptUuid = attemptUuid;
        rememberAttemptUuid(attemptUuid);
        setState('STARTING');
        try {
            const response = await fetch(`/api/public/start/${encodeURIComponent(alias)}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Idempotency-Key': attemptUuid,
                },
                body: JSON.stringify({
                    full_name: state.identity.fullName.trim(),
                    origin_school: optionalValue(state.identity.originSchool),
                    class_name: optionalValue(state.identity.className),
                    phone: state.identity.whatsapp.trim(),
                    marketing_consent: state.identity.marketingConsent,
                }),
            });
            let payload = null;
            try {
                payload = await response.json();
            } catch (error) {
                // The status-specific safe messages below do not require a body.
            }
            if ((response.status === 201 || response.status === 200) && payload && payload.ok === true
                && isUuidV4(payload.attempt_uuid) && payload.attempt_uuid === attemptUuid) {
                await loadRealQuiz(attemptUuid);
                return;
            }
            if (response.status === 404) {
                showError('Link kuis tidak tersedia atau sudah tidak aktif.');
            } else if (response.status === 409 && payload && payload.error === 'idempotency_conflict') {
                showError('Sesi ini tidak cocok dengan perangkat atau link yang digunakan.', null, true);
            } else if (response.status === 400 || response.status === 415 || response.status === 422) {
                showError('Data belum dapat diproses. Periksa kembali data yang diisi.');
            } else {
                showError('Terjadi gangguan sementara. Silakan coba lagi.', startRealQuiz);
            }
        } catch (error) {
            showError('Terjadi gangguan sementara. Silakan coba lagi.', startRealQuiz);
        }
    };

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

    const programCards = () => {
        const cards = element('div', 'pq-program-cards');

        presentationPrograms.forEach((program) => {
            if (!program || typeof program !== 'object' || typeof program.code !== 'string'
                || typeof program.display_name !== 'string') {
                return;
            }

            const card = element('article', 'pq-program-card');
            const image = element('img', 'pq-program-card__mascot');
            const label = element('span', 'pq-program-card__label', program.code);
            const name = element('span', 'pq-program-card__name', program.display_name);
            const color = allowedColor(program.primary_color) ? program.primary_color : null;

            if (color) {
                card.style.setProperty('--program-card-color', color);
            }
            image.src = validPresentationImagePath(program.mascot_path)
                ? program.mascot_path
                : '/assets/mascots/mascot-all.png';
            image.alt = '';
            image.loading = 'eager';
            card.append(image, label, name);
            cards.append(card);
        });

        return cards.childElementCount > 0 ? cards : null;
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

        const badges = element('div', 'pq-landing-badges');
        badges.append(
            element('span', 'pq-kicker pq-kicker--lime', 'TEBAK JURUSANMU'),
            element('span', 'pq-kicker pq-kicker--cyan', '± 2 menit'),
            element('span', 'pq-kicker pq-kicker--pink', 'Personality quiz'),
        );
        view.append(badges);

        const heading = element('h1', 'pq-heading');
        heading.append('Jurusan apa yang ');
        heading.append(element('span', 'pq-heading__accent', 'cocok'));
        heading.append(' buat kamu?');
        view.append(heading);
        view.append(element('p', 'pq-copy', 'Jawab beberapa pertanyaan seru dan kenali gaya yang paling dekat denganmu.'));

        const cards = programCards();
        if (cards) {
            view.append(cards);
        } else {
            const art = element('div', 'pq-landing-art');
            const mascot = element('img', 'pq-mascot-all');
            mascot.src = '/assets/mascots/mascot-all.png';
            mascot.alt = 'Ilustrasi maskot SMK Match';
            art.append(mascot);
            view.append(art);
        }

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
        if (config.inputMode) {
            input.inputMode = config.inputMode;
        }
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
        createField(form, { id: 'origin-school', name: 'originSchool', label: 'Asal sekolah', labelClass: 'pq-field-label--lime', placeholder: 'Kamu bersekolah dimana', autocomplete: 'organization' });
        createField(form, { id: 'class-name', name: 'className', label: 'Kelas', labelClass: 'pq-field-label--cyan', placeholder: 'Contoh: IX-A', autocomplete: 'organization-title' });
        createField(form, { id: 'whatsapp', name: 'whatsapp', label: 'WhatsApp', labelClass: 'pq-field-label--pink', placeholder: 'Contoh: 081234567890', autocomplete: 'tel', type: 'tel', inputMode: 'numeric' });

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
            const fields = realMode ? [
                ['fullName', 'full-name', 'Nama perlu diisi.'],
                ['whatsapp', 'whatsapp', 'Nomor WhatsApp perlu diisi.'],
            ] : [
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
            if (!validWhatsapp(state.identity.whatsapp)) {
                const input = document.getElementById('whatsapp');
                const error = document.getElementById('whatsapp-error');
                input?.setAttribute('aria-invalid', 'true');
                if (error) {
                    error.textContent = 'Gunakan nomor WhatsApp 08xxxxxxxxxx (10–13 digit, angka saja).';
                }
                input?.focus();
                return;
            }
            if (realMode) {
                startRealQuiz();
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
            ['✦', 'Pilih jawaban yang paling menggambarkan dirimu.'],
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

        if (validQuestionImagePath(question.image_path)) {
            const image = element('img', 'pq-question-image');
            image.src = question.image_path;
            image.alt = '';
            image.loading = 'lazy';
            view.append(image);
        }
        if (typeof question.help_text === 'string' && question.help_text.trim() !== '') {
            view.append(element('p', 'pq-question-help', question.help_text));
        }

        const fieldset = element('fieldset', 'pq-options');
        const legend = element('legend', 'pq-question', question.text);
        fieldset.append(legend);

        question.options.forEach((option, optionIndex) => {
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
            const answer = element('span', 'pq-option__answer');
            answer.append(
                element('span', 'pq-option__letter', String.fromCharCode(65 + optionIndex)),
                element('span', 'pq-option__copy', option.text),
            );
            label.append(input, answer, checkMarker);
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
        const next = button(isFinal ? (realMode ? 'Selesai ✦' : 'Lihat Hasil ✦') : 'Lanjut →');
        next.disabled = !state.answers[question.id];
        next.addEventListener('click', () => {
            if (!state.answers[question.id]) {
                return;
            }
            if (isFinal) {
                if (realMode) {
                    submitRealAnswers();
                    return;
                }
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
        view.append(element('h1', 'pq-heading pq-heading--compact', 'Meramu Hasilmu...'));
        view.append(element('p', 'pq-copy', 'Sebentar ya, kami sedang merangkai kecenderunganmu.'));
        const loader = element('div', 'pq-loader');
        loader.setAttribute('aria-label', 'Memproses langkah berikutnya');
        loader.setAttribute('role', 'status');
        loader.append(element('span', 'pq-loader__inner', '✦'));
        view.append(loader);
        const steps = element('ul', 'pq-analysis-steps');
        [
            'Merapikan jawabanmu...',
            'Meramu kecenderunganmu...',
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
        view.append(element('p', 'pq-copy', 'Menampilkan hasilmu...'));
        if (isUuidV4(state.attemptUuid)) {
            analysisTimer = window.setTimeout(() => {
                window.location.assign(`/result/attempt/${encodeURIComponent(state.attemptUuid)}`);
            }, reducedMotion ? 0 : 180);
        }
        return view;
    };

    const renderLoading = (copy) => {
        const view = screen('pq-screen--centered pq-analyzing');
        view.append(element('h1', 'pq-heading pq-heading--compact', copy));
        const loader = element('div', 'pq-loader');
        loader.setAttribute('role', 'status');
        loader.append(element('span', 'pq-loader__inner', '✦'));
        view.append(loader);
        return view;
    };

    const renderError = () => {
        const view = screen('pq-screen--centered');
        view.append(element('h1', 'pq-heading pq-heading--compact', 'Belum bisa dilanjutkan'));
        view.append(element('p', 'pq-copy', state.errorMessage));
        if (state.retry) {
            const retry = button('Coba lagi');
            retry.addEventListener('click', () => state.retry());
            view.append(retry);
        }
        if (state.canStartNewSession) {
            const restart = button('Mulai sesi baru', 'pq-button pq-button--secondary');
            restart.addEventListener('click', () => {
                forgetAttemptUuid();
                state.attemptUuid = null;
                setState('IDENTITY');
            });
            view.append(restart);
        }
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
            STARTING: () => renderLoading('Menyiapkan sesi...'),
            LOADING_QUIZ: () => renderLoading('Memuat kuis...'),
            SUBMITTING: () => renderLoading('Menyimpan jawabanmu...'),
            ERROR: renderError,
        };
        root.append((views[state.screen] || renderLanding)());
    };

    render();
})();
