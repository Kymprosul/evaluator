const app = document.getElementById("classroom-app");

if (app) {
    const canvas = document.getElementById("roulette-canvas");
    const context = canvas.getContext("2d");
    const winnerDisplay = document.getElementById("winner-display");
    const spinButton = document.getElementById("spin-button");
    const resetButton = document.getElementById("reset-button");
    const currentStudent = document.getElementById("current-student");
    const feedback = document.getElementById("classroom-feedback");
    const recentList = document.getElementById("recent-evaluations");
    const totalElement = document.getElementById("stat-total");
    const remainingElement = document.getElementById("stat-remaining");
    const evaluatedElement = document.getElementById("stat-evaluated");
    const evaluationButtons = Array.from(document.querySelectorAll(".eval-button"));
    const attendanceContainer = document.getElementById("attendance-students");
    const attendanceStartButton = document.getElementById("attendance-start");
    const attendanceFinishButton = document.getElementById("attendance-finish");
    const attendanceStatus = document.getElementById("attendance-status");
    const syncBanner = document.getElementById("sync-banner");
    const syncBannerText = document.getElementById("sync-banner-text");
    const conflictBox = document.getElementById("sync-conflicts");
    const conflictList = document.getElementById("sync-conflicts-list");

    let csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";
    const spinUrl = app.dataset.spinUrl;
    const evaluateUrl = app.dataset.evaluateUrl;
    const resetUrl = app.dataset.resetUrl;
    const attendanceUrl = app.dataset.attendanceUrl;
    const stateUrl = app.dataset.stateUrl;

    // serverState is the last state the server confirmed; state is serverState with the
    // actions still waiting in the queue applied on top, which is what the screen shows.
    let serverState = JSON.parse(app.dataset.initialState);
    let state = serverState;
    const classId = serverState.class.id;

    let rotation = 0;
    let isAnimating = false;
    let pendingSpinFrame = null;
    let pendingSpinStart = 0;
    let lastWinnerLabel = serverState.pending_evaluation?.student?.label || "";
    let attendanceAudioContext = null;

    // ============================================================
    // Storage — localStorage can be full or disabled; callers keep an in-memory copy
    // ============================================================
    const Store = {
        get(key, fallback) {
            try {
                const raw = localStorage.getItem(key);
                return raw ? JSON.parse(raw) : fallback;
            } catch {
                return fallback;
            }
        },
        set(key, value) {
            try {
                localStorage.setItem(key, JSON.stringify(value));
            } catch {
                // Kept in memory only.
            }
        },
        remove(key) {
            try {
                localStorage.removeItem(key);
            } catch {
                // Ignore.
            }
        },
        keys(prefix) {
            const found = [];
            try {
                for (let i = 0; i < localStorage.length; i++) {
                    const key = localStorage.key(i);
                    if (key && key.startsWith(prefix)) found.push(key);
                }
            } catch {
                // Ignore.
            }
            return found;
        },
    };

    function newToken(prefix) {
        let random = "";
        if (window.crypto?.getRandomValues) {
            const bytes = new Uint8Array(8);
            window.crypto.getRandomValues(bytes);
            random = Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
        } else {
            random = Math.random().toString(36).slice(2, 12);
        }
        return prefix + "_" + Date.now().toString(36) + "_" + random;
    }

    function localDay() {
        const now = new Date();
        return now.getFullYear() + "-" + String(now.getMonth() + 1).padStart(2, "0") + "-" + String(now.getDate()).padStart(2, "0");
    }

    // ============================================================
    // Network — every request has a timeout and a clear outcome:
    //   ok | rejected (server said no, drop it) | auth (log in again) | retry (anything else)
    // ============================================================
    const REQUEST_TIMEOUT_MS = 15000;

    async function send(method, url, params) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
        const options = {
            method: method,
            headers: { "X-CSRF-Token": csrfToken },
            credentials: "same-origin",
            cache: "no-store",
            signal: controller.signal,
        };
        if (method === "POST") {
            options.headers["Content-Type"] = "application/x-www-form-urlencoded; charset=UTF-8";
            options.body = params;
        }

        let response;
        let result = null;
        try {
            response = await fetch(url, options);
            try {
                result = await response.json();
            } catch {
                result = null;
            }
        } catch {
            return { kind: "retry", message: "Sin conexión con el servidor." };
        } finally {
            clearTimeout(timer);
        }

        if (response.status === 401) return { kind: "auth" };
        if (response.status === 419) return { kind: "csrf" };
        if (response.ok && result && result.ok === true) return { kind: "ok", data: result.data || {} };
        if (response.status === 422 && result && result.ok === false) {
            return { kind: "rejected", message: result.message || "El servidor rechazó la acción." };
        }
        return { kind: "retry", message: "El servidor no respondió bien (HTTP " + response.status + ")." };
    }

    async function refreshFromServer() {
        const separator = stateUrl.includes("?") ? "&" : "?";
        const outcome = await send("GET", stateUrl + separator + "class_id=" + encodeURIComponent(classId));
        if (outcome.kind === "ok") {
            if (outcome.data.csrf_token) csrfToken = outcome.data.csrf_token;
            if (outcome.data.state) setServerState(outcome.data.state);
        }
        return outcome;
    }

    async function post(url, params) {
        let outcome = await send("POST", url, params);
        if (outcome.kind !== "csrf") return outcome;

        // The session changed (for example after logging in again in another tab): get a fresh token.
        const refreshed = await refreshFromServer();
        if (refreshed.kind !== "ok") return refreshed.kind === "auth" ? refreshed : { kind: "retry", message: refreshed.message };

        outcome = await send("POST", url, params);
        return outcome.kind === "csrf" ? { kind: "auth" } : outcome;
    }

    function backoffMs(attempt) {
        const steps = [2000, 4000, 8000, 15000, 30000];
        return steps[Math.min(attempt, steps.length) - 1];
    }

    // ============================================================
    // Local state — rebuild the screen from serverState + queued actions
    // ============================================================
    function cloneState(source) {
        return JSON.parse(JSON.stringify(source));
    }

    function startLocalCycle(target) {
        target.cycle = Object.assign({}, target.cycle, { id: null, number: Number(target.cycle.number) + 1, status: "open" });
        target.remaining_students = (target.attendance?.students || []).map((student) => Object.assign({}, student));
        target.stats.evaluated_students = 0;
    }

    function applyLocally(target, action) {
        const pending = target.pending_evaluation;

        if (action.type === "spin") {
            if (pending) {
                // The server already has this spin (its answer got lost); remember the link.
                if (Number(pending.student.id) === Number(action.studentId)) pending.spinToken = action.token;
                return;
            }
            const index = target.remaining_students.findIndex((s) => Number(s.id) === Number(action.studentId));
            if (index === -1) return;
            const student = target.remaining_students.splice(index, 1)[0];
            target.pending_evaluation = { id: null, spinToken: action.token, score: null, student: student };
            return;
        }

        if (action.type === "evaluate") {
            if (!pending) return;
            const matches = (action.spinToken && pending.spinToken === action.spinToken)
                || (action.evaluationId && Number(pending.id) === Number(action.evaluationId))
                || Number(pending.student.id) === Number(action.studentId);
            if (!matches) return;

            target.pending_evaluation = null;
            target.recent_evaluations.unshift({ student: pending.student.label, score: action.score, evaluated_at: null });
            target.recent_evaluations = target.recent_evaluations.slice(0, 8);
            target.stats.evaluated_students += 1;
            // The server opens the next round by itself after the last evaluation.
            if (target.remaining_students.length === 0) startLocalCycle(target);
            return;
        }

        if (action.type === "reset" && !pending) {
            startLocalCycle(target);
        }
    }

    function rebase() {
        const next = cloneState(serverState);
        Queue.items().forEach((action) => applyLocally(next, action));

        next.stats.remaining_students = next.remaining_students.length;
        next.stats.can_spin = next.cycle.status === "open" && !next.pending_evaluation && next.remaining_students.length > 0;
        next.stats.can_reset = next.stats.total_students > 0 && !next.pending_evaluation;
        state = next;
    }

    function setServerState(incoming) {
        serverState = incoming;
        rebase();
        renderState();
    }

    // ============================================================
    // Queue — spins, evaluations and resets, sent one by one, in order, until they get through
    // ============================================================
    const Queue = (function () {
        const storageKey = "eval_queue_" + classId;
        let items = migrateLegacy(Store.get(storageKey, []));
        let running = false;
        let attempts = 0;
        let status = "idle"; // idle | syncing | retrying | auth
        let lastError = "";
        let retryTimer = null;

        // Queues written by the previous version used tempId and a separate id map.
        function migrateLegacy(list) {
            if (!Array.isArray(list)) return [];
            const legacyMap = Store.get("eval_id_map_" + classId, {});
            const migrated = list.filter((a) => a && a.type).map(function (a) {
                if (a.token) return a;
                const action = { token: a.tempId || newToken(a.type), type: a.type, studentId: a.studentId || null, ts: a.timestamp || Date.now() };
                if (a.type === "evaluate") {
                    action.score = a.score;
                    if (typeof a.evaluationId === "string" && a.evaluationId.startsWith("spin_")) {
                        action.spinToken = a.evaluationId;
                        action.evaluationId = legacyMap[a.evaluationId] || null;
                    } else {
                        action.evaluationId = a.evaluationId || null;
                    }
                }
                return action;
            });
            Store.remove("eval_id_map_" + classId);
            return migrated;
        }

        function persist() {
            Store.set(storageKey, items);
        }

        function requestFor(action) {
            const params = new URLSearchParams();
            params.append("class_id", String(classId));
            params.append("client_token", action.token);

            if (action.type === "spin") {
                params.append("student_id", String(action.studentId));
                return { url: spinUrl, params: params };
            }
            if (action.type === "evaluate") {
                params.append("score", action.score);
                if (action.spinToken) params.append("spin_token", action.spinToken);
                if (action.evaluationId) params.append("evaluation_id", String(action.evaluationId));
                return { url: evaluateUrl, params: params };
            }
            return { url: resetUrl, params: params };
        }

        async function handleRejection(action, message) {
            items = items.filter((a) => a.token !== action.token);
            persist();
            await refreshFromServer();

            if (action.type === "spin") {
                const serverPending = serverState.pending_evaluation;
                const sameStudent = serverPending && Number(serverPending.student.id) === Number(action.studentId);
                items = items.filter(function (a) {
                    if (a.type !== "evaluate" || a.spinToken !== action.token) return true;
                    if (sameStudent) {
                        // The spin did reach the server earlier: score that evaluation directly.
                        a.spinToken = null;
                        a.evaluationId = serverPending.id;
                        return true;
                    }
                    Conflicts.add("No se guardó la evaluación de " + (a.studentLabel || "un alumno") + " (" + a.score + "): " + message);
                    return false;
                });
                persist();
                if (!sameStudent) Conflicts.add("No se guardó el sorteo de " + (action.studentLabel || "un alumno") + ": " + message);
            } else if (action.type === "evaluate") {
                Conflicts.add("No se guardó la evaluación de " + (action.studentLabel || "un alumno") + " (" + action.score + "): " + message);
            } else {
                Conflicts.add("No se creó la nueva ronda: " + message);
            }

            rebase();
            renderState();
        }

        async function run() {
            if (running) return;
            running = true;
            clearTimeout(retryTimer);

            try {
                while (items.length > 0) {
                    const action = items[0];
                    status = "syncing";
                    SyncUI.update();

                    const request = requestFor(action);
                    const outcome = await post(request.url, request.params);

                    if (outcome.kind === "ok") {
                        attempts = 0;
                        items = items.filter((a) => a.token !== action.token);
                        persist();
                        if (outcome.data.state) {
                            setServerState(outcome.data.state);
                        } else {
                            rebase();
                            renderState();
                        }
                        continue;
                    }

                    if (outcome.kind === "rejected") {
                        attempts = 0;
                        await handleRejection(action, outcome.message);
                        continue;
                    }

                    if (outcome.kind === "auth") {
                        status = "auth";
                        return;
                    }

                    attempts += 1;
                    lastError = outcome.message || "";
                    status = "retrying";
                    retryTimer = setTimeout(run, backoffMs(attempts));
                    return;
                }

                status = "idle";
                lastError = "";
            } finally {
                running = false;
                SyncUI.update();
            }
        }

        return {
            items: () => items.slice(),
            add(action) {
                items.push(action);
                persist();
                rebase();
                renderState();
                SyncUI.update();
                setTimeout(run, 0);
            },
            kick() {
                if (running) return;
                if (status !== "retrying") attempts = 0;
                setTimeout(run, 0);
            },
            reloadFromStorage() {
                items = migrateLegacy(Store.get(storageKey, []));
                rebase();
                renderState();
                SyncUI.update();
            },
            status: () => ({ pending: items.length, status: status, attempts: attempts, lastError: lastError, oldest: items[0]?.ts || null }),
            storageKey: storageKey,
        };
    })();

    // ============================================================
    // Attendance — only while a roll call is open; saved locally on every tap
    // ============================================================
    const Attendance = (function () {
        const SYNC_DELAY_MS = 1500;
        const KEEP_DAYS = 14;
        const keyPrefix = "eval_attendance_" + classId + "_";
        const sessionKey = "eval_attendance_session_" + classId;
        const today = serverState.attendance?.date || localDay();
        const pageDay = localDay();

        let active = Store.get(sessionKey, null) === today;
        let records = loadRecords();
        let selected = initialSelection();
        let running = false;
        let attempts = 0;
        let status = "idle"; // idle | syncing | retrying | auth
        let lastError = "";
        let timer = null;

        // record: { ids, rev, syncedRev, ts } — unsynced while rev !== syncedRev
        function normalize(raw) {
            if (!raw || !Array.isArray(raw.ids)) return null;
            if (typeof raw.rev === "number") return raw;
            // Format of the previous version: { ids, synced, ts }
            return { ids: raw.ids, rev: 1, syncedRev: raw.synced ? 1 : 0, ts: raw.ts || Date.now() };
        }

        function loadRecords() {
            const loaded = {};
            Store.keys(keyPrefix).forEach(function (key) {
                const date = key.slice(keyPrefix.length);
                const record = normalize(Store.get(key, null));
                if (!record || !/^\d{4}-\d{2}-\d{2}$/.test(date)) return;

                const ageDays = (Date.parse(today) - Date.parse(date)) / 86400000;
                if (record.rev === record.syncedRev && ageDays > KEEP_DAYS) {
                    Store.remove(key);
                    return;
                }
                loaded[date] = record;
            });
            return loaded;
        }

        function initialSelection() {
            const record = records[today];
            if (record && record.rev !== record.syncedRev) return new Set(record.ids.map(Number));
            return new Set((serverState.attendance?.present_student_ids || []).map(Number));
        }

        function save(date, record) {
            records[date] = record;
            Store.set(keyPrefix + date, record);
        }

        function unsyncedDates() {
            return Object.keys(records).filter((date) => records[date].rev !== records[date].syncedRev).sort();
        }

        function schedule(delay) {
            clearTimeout(timer);
            timer = setTimeout(run, delay);
        }

        async function run() {
            if (running) return;
            running = true;
            clearTimeout(timer);

            try {
                let dates = unsyncedDates();
                while (dates.length > 0) {
                    const date = dates[0];
                    const sent = Object.assign({}, records[date]);
                    status = "syncing";
                    SyncUI.update();

                    const params = new URLSearchParams();
                    params.append("class_id", String(classId));
                    params.append("attendance_date", date);
                    sent.ids.forEach((id) => params.append("present_student_ids[]", String(id)));

                    const outcome = await post(attendanceUrl, params);

                    if (outcome.kind === "ok" || outcome.kind === "rejected") {
                        attempts = 0;
                        // Taps made while the request was travelling raise rev again and are sent next round.
                        const latest = records[date];
                        save(date, Object.assign({}, latest, { syncedRev: Math.max(latest.syncedRev, sent.rev) }));
                        if (outcome.kind === "rejected") {
                            Conflicts.add("No se guardó la asistencia del " + date + ": " + outcome.message);
                        } else if (outcome.data.state) {
                            setServerState(outcome.data.state);
                        }
                        dates = unsyncedDates();
                        continue;
                    }

                    if (outcome.kind === "auth") {
                        status = "auth";
                        return;
                    }

                    attempts += 1;
                    lastError = outcome.message || "";
                    status = "retrying";
                    schedule(backoffMs(attempts));
                    return;
                }

                status = "idle";
                lastError = "";
            } finally {
                running = false;
                SyncUI.update();
                renderAttendance();
            }
        }

        return {
            today: today,
            isActive: () => active,
            isPresent: (id) => selected.has(Number(id)),
            hasData: () => !!serverState.attendance?.taken || !!records[today],
            start() {
                if (localDay() !== pageDay) {
                    setFeedback("Ha cambiado el día desde que abriste la página. Recárgala para pasar la lista de hoy.", "error");
                    return;
                }
                active = true;
                Store.set(sessionKey, today);
                renderAttendance();
            },
            finish() {
                active = false;
                Store.remove(sessionKey);
                renderAttendance();
                if (unsyncedDates().length > 0) {
                    attempts = 0;
                    schedule(0);
                }
            },
            toggle(studentId) {
                if (!active) return false;
                const id = Number(studentId);
                const nowPresent = !selected.has(id);
                if (nowPresent) selected.add(id);
                else selected.delete(id);

                const previous = records[today] || { rev: 0, syncedRev: 0 };
                save(today, { ids: Array.from(selected), rev: previous.rev + 1, syncedRev: previous.syncedRev, ts: Date.now() });
                SyncUI.update();
                schedule(SYNC_DELAY_MS);
                return nowPresent;
            },
            onServerState() {
                const record = records[today];
                if (!active && (!record || record.rev === record.syncedRev)) {
                    selected = new Set((serverState.attendance?.present_student_ids || []).map(Number));
                }
            },
            kick() {
                if (running || unsyncedDates().length === 0) return;
                if (status !== "retrying") attempts = 0;
                schedule(0);
            },
            unsynced: () => unsyncedDates().map((date) => ({ date: date, ids: records[date].ids, ts: records[date].ts })),
            status: () => ({ pending: unsyncedDates().length, status: status, attempts: attempts, lastError: lastError }),
        };
    })();

    // ============================================================
    // Conflicts — actions the server refused; kept until the teacher reads them
    // ============================================================
    const Conflicts = (function () {
        const storageKey = "eval_conflicts_" + classId;
        let messages = Store.get(storageKey, []);

        return {
            add(message) {
                messages.push(new Date().toLocaleTimeString() + " · " + message);
                messages = messages.slice(-20);
                Store.set(storageKey, messages);
                SyncUI.update();
            },
            clear() {
                messages = [];
                Store.remove(storageKey);
                SyncUI.update();
            },
            list: () => messages.slice(),
        };
    })();

    // ============================================================
    // Sync UI — dots, warning banner and backup download
    // ============================================================
    const SyncUI = (function () {
        const SLOW_MS = 20000;

        function setDot(element, level) {
            if (!element) return;
            element.classList.remove("pending", "synced", "error");
            element.classList.add(level);
        }

        function levelFor(info) {
            if (info.pending === 0) return "synced";
            if (info.status === "auth" || (info.status === "retrying" && info.attempts >= 2)) return "error";
            return "pending";
        }

        function update() {
            const queueInfo = Queue.status();
            const attendanceInfo = Attendance.status();
            const queueLevel = levelFor(queueInfo);
            const attendanceLevel = levelFor(attendanceInfo);

            setDot(document.getElementById("eval-sync-indicator"), queueLevel);
            setDot(document.getElementById("eval-score-indicator"), queueLevel);
            setDot(document.getElementById("sync-indicator"), attendanceLevel);

            const total = queueInfo.pending + attendanceInfo.pending;
            const authLost = queueInfo.status === "auth" || attendanceInfo.status === "auth";
            const failing = queueLevel === "error" || attendanceLevel === "error";
            const slow = queueInfo.oldest !== null && Date.now() - queueInfo.oldest > SLOW_MS;

            if (syncBanner) {
                if (total > 0 && (authLost || failing || slow)) {
                    const parts = [];
                    if (queueInfo.pending > 0) parts.push(queueInfo.pending + (queueInfo.pending === 1 ? " acción de la ruleta" : " acciones de la ruleta"));
                    if (attendanceInfo.pending > 0) parts.push("la asistencia");
                    let text = "⚠ Sin guardar en el servidor: " + parts.join(" y ") + ". Está a salvo en este navegador.";
                    if (authLost) text += " La sesión ha caducado: inicia sesión en otra pestaña y pulsa Reintentar.";
                    else if (queueInfo.lastError || attendanceInfo.lastError) text += " (" + (queueInfo.lastError || attendanceInfo.lastError) + ")";
                    syncBannerText.textContent = text;
                    syncBanner.hidden = false;
                } else {
                    syncBanner.hidden = true;
                }
            }

            if (conflictBox) {
                const messages = Conflicts.list();
                conflictList.innerHTML = "";
                messages.forEach(function (message) {
                    const li = document.createElement("li");
                    li.textContent = message;
                    conflictList.appendChild(li);
                });
                conflictBox.hidden = messages.length === 0;
            }

            if (attendanceStatus) {
                const labels = {
                    synced: Attendance.hasData() ? "✓ Guardada" : "",
                    pending: "Guardando…",
                    error: "⚠ Sin guardar en el servidor",
                };
                attendanceStatus.textContent = labels[attendanceLevel];
                attendanceStatus.dataset.level = attendanceLevel;
            }
        }

        function downloadBackup() {
            const payload = {
                format: "evaluator-backup",
                version: 1,
                created_at: new Date().toISOString(),
                class: { id: classId, code: serverState.class.code, name: serverState.class.name },
                actions: Queue.items(),
                attendance: Attendance.unsynced(),
            };
            const slug = String(serverState.class.name || serverState.class.code || classId).toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") || "clase";
            const stamp = new Date().toISOString().slice(0, 16).replace(/[-:T]/g, "");
            const blob = new Blob([JSON.stringify(payload, null, 2)], { type: "application/json" });
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.href = url;
            link.download = "evaluator-respaldo-" + slug + "-" + stamp + ".json";
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 2000);
        }

        return { update: update, downloadBackup: downloadBackup };
    })();

    function retryAll() {
        Queue.kick();
        Attendance.kick();
    }

    function hasUnsavedWork() {
        return Queue.status().pending > 0 || Attendance.status().pending > 0;
    }

    // ============================================================
    // Drawing
    // ============================================================
    function playAttendanceBling(isSelecting = true) {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) {
            return;
        }

        attendanceAudioContext ||= new AudioContextClass();
        const now = attendanceAudioContext.currentTime;
        const gain = attendanceAudioContext.createGain();
        const firstTone = attendanceAudioContext.createOscillator();
        const secondTone = attendanceAudioContext.createOscillator();

        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(isSelecting ? 0.055 : 0.035, now + 0.015);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.24);

        firstTone.type = "sine";
        firstTone.frequency.setValueAtTime(isSelecting ? 740 : 520, now);
        firstTone.frequency.exponentialRampToValueAtTime(isSelecting ? 980 : 390, now + 0.18);

        secondTone.type = "triangle";
        secondTone.frequency.setValueAtTime(isSelecting ? 1480 : 780, now + 0.035);

        firstTone.connect(gain);
        secondTone.connect(gain);
        gain.connect(attendanceAudioContext.destination);

        firstTone.start(now);
        secondTone.start(now + 0.035);
        firstTone.stop(now + 0.22);
        secondTone.stop(now + 0.18);
    }

    function colorFor(index) {
        const palette = [
            "#c7e0d8",
            "#f0d3aa",
            "#d9d3eb",
            "#c9d9f3",
            "#f2d0c7",
            "#d5e5b1",
            "#e7d7c5",
            "#d3e0cb",
        ];

        return palette[index % palette.length];
    }

    function currentSegments() {
        return Array.isArray(state.remaining_students) ? state.remaining_students : [];
    }

    function drawWheel(segmentsOverride) {
        const size = Math.min(canvas.clientWidth || canvas.width, canvas.clientHeight || canvas.height || canvas.width);
        canvas.width = size;
        canvas.height = size;

        context.clearRect(0, 0, canvas.width, canvas.height);

        const segments = segmentsOverride || currentSegments();
        if (!segments.length) {
            drawEmptyWheel();
            return;
        }

        const center = canvas.width / 2;
        const radius = center - 18;
        const arc = (Math.PI * 2) / segments.length;

        context.save();
        context.translate(center, center);
        context.rotate(rotation);

        segments.forEach((segment, index) => {
            const start = index * arc;
            const end = start + arc;

            context.beginPath();
            context.moveTo(0, 0);
            context.arc(0, 0, radius, start, end);
            context.closePath();
            context.fillStyle = colorFor(index);
            context.fill();
            context.lineWidth = 2;
            context.strokeStyle = "rgba(255,255,255,0.9)";
            context.stroke();
        });

        context.restore();

        context.beginPath();
        context.arc(center, center, center * 0.21, 0, Math.PI * 2);
        context.fillStyle = "rgba(255,255,255,0.94)";
        context.fill();
        context.lineWidth = 4;
        context.strokeStyle = "rgba(22,93,74,0.18)";
        context.stroke();

        context.fillStyle = "#2f2619";
        context.textAlign = "center";
        context.font = `${Math.max(16, center * 0.09)}px Georgia`;
        context.fillText(String(segments.length), center, center + 6);
        context.font = `${Math.max(10, center * 0.036)}px Georgia`;
        context.fillStyle = "#6f6656";
        context.fillText("pendientes", center, center + 26);

        drawPointer(center, radius);
    }

    function drawPointer(center, radius) {
        context.save();
        context.translate(center, center);
        context.fillStyle = "#2f2619";
        context.beginPath();
        context.moveTo(0, -radius - 12);
        context.lineTo(14, -radius + 18);
        context.lineTo(-14, -radius + 18);
        context.closePath();
        context.fill();
        context.restore();
    }

    function drawEmptyWheel() {
        const center = canvas.width / 2;
        const radius = center - 18;

        context.beginPath();
        context.arc(center, center, radius, 0, Math.PI * 2);
        context.fillStyle = "rgba(255,255,255,0.72)";
        context.fill();
        context.lineWidth = 4;
        context.strokeStyle = "rgba(22,93,74,0.14)";
        context.stroke();

        context.fillStyle = "#6f6656";
        context.textAlign = "center";
        context.font = `${Math.max(14, center * 0.05)}px Georgia`;
        context.fillText("No quedan alumnos", center, center - 2);
        context.font = `${Math.max(12, center * 0.035)}px Georgia`;
        context.fillText("Evalúa el actual o crea una nueva ronda", center, center + 24);

        drawPointer(center, radius);
    }

    function setFeedback(message, type = "info") {
        feedback.textContent = message || "";
        feedback.dataset.type = type;
        feedback.style.color = type === "error" ? "#9f3e2e" : type === "success" ? "#165d4a" : "#6f6656";
    }

    function renderRecentEvaluations() {
        recentList.innerHTML = "";

        if (!state.recent_evaluations.length) {
            const item = document.createElement("li");
            item.innerHTML = "<span>No hay evaluaciones todavía.</span>";
            recentList.appendChild(item);
            return;
        }

        state.recent_evaluations.forEach((item) => {
            const li = document.createElement("li");
            li.innerHTML = `<span>${escapeHtml(item.student)}</span><strong>${escapeHtml(item.score)}</strong>`;
            recentList.appendChild(li);
        });
    }

    function attendanceStudents() {
        return Array.isArray(state.attendance?.students) ? state.attendance.students : [];
    }

    function renderAttendance() {
        if (!attendanceContainer) {
            return;
        }

        const active = Attendance.isActive();
        const attendanceDateEl = document.getElementById("attendance-date");
        if (attendanceDateEl) {
            attendanceDateEl.textContent = Attendance.today;
        }

        if (attendanceStartButton) {
            attendanceStartButton.hidden = active;
        }
        if (attendanceFinishButton) {
            attendanceFinishButton.hidden = !active;
        }

        attendanceContainer.classList.toggle("is-locked", !active);
        attendanceContainer.innerHTML = "";
        const students = attendanceStudents();

        if (!students.length) {
            const empty = document.createElement("p");
            empty.className = "muted";
            empty.textContent = "No hay alumnos en esta clase.";
            attendanceContainer.appendChild(empty);
            return;
        }

        students.forEach((student) => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "attendance-student-button";
            button.textContent = student.label;
            button.dataset.studentId = String(student.id);

            if (student.vip) {
                button.classList.add("is-vip");
                button.disabled = true;
                button.title = "VIP";
                attendanceContainer.appendChild(button);
                return;
            }

            if (Attendance.isPresent(student.id)) {
                button.classList.add("is-present");
            }

            button.disabled = !active;
            button.addEventListener("click", () => {
                const nowPresent = Attendance.toggle(student.id);
                button.classList.toggle("is-present", nowPresent);
                playAttendanceBling(nowPresent);
            });

            attendanceContainer.appendChild(button);
        });

        SyncUI.update();
    }

    function renderState() {
        Attendance.onServerState();

        totalElement.textContent = String(state.stats.total_students);
        remainingElement.textContent = String(state.stats.remaining_students);
        evaluatedElement.textContent = String(state.stats.evaluated_students);

        const pending = state.pending_evaluation;
        currentStudent.textContent = isAnimating
            ? "Girando..."
            : pending
                ? pending.student.label
                : "Todavía no se ha seleccionado a nadie.";

        spinButton.disabled = !state.stats.can_spin || isAnimating;
        resetButton.disabled = !state.stats.can_reset || isAnimating;

        evaluationButtons.forEach((button) => {
            button.disabled = pending === null || isAnimating;
        });

        updateWinnerDisplay();

        renderRecentEvaluations();
        renderAttendance();
        if (!isAnimating) drawWheel();
    }

    function applyWinnerFontSize(label) {
        const length = label.length;

        if (length > 26) {
            winnerDisplay.style.fontSize = "2rem";
        } else if (length > 20) {
            winnerDisplay.style.fontSize = "2.5rem";
        } else if (length > 14) {
            winnerDisplay.style.fontSize = "3.2rem";
        } else {
            winnerDisplay.style.fontSize = "4.2rem";
        }
    }

    function updateWinnerDisplay() {
        const label = String(lastWinnerLabel || "").trim();

        if (!label) {
            winnerDisplay.classList.remove("is-visible");
            winnerDisplay.textContent = "";
            winnerDisplay.style.display = "none";
            return;
        }

        winnerDisplay.textContent = label;
        winnerDisplay.classList.add("is-visible");
        winnerDisplay.style.display = "block";
        winnerDisplay.style.position = "absolute";
        winnerDisplay.style.left = "50%";
        winnerDisplay.style.bottom = "0.75rem";
        winnerDisplay.style.transform = "translateX(-50%)";
        winnerDisplay.style.color = "#c01818";
        winnerDisplay.style.fontWeight = "900";
        winnerDisplay.style.lineHeight = "1";
        winnerDisplay.style.whiteSpace = "nowrap";
        winnerDisplay.style.letterSpacing = "0.02em";
        winnerDisplay.style.textShadow = "0 3px 0 rgba(255, 255, 255, 0.95), 0 0 22px rgba(192, 24, 24, 0.28)";
        winnerDisplay.style.zIndex = "40";
        winnerDisplay.style.pointerEvents = "none";
        applyWinnerFontSize(label);
    }

    function showWinnerDisplay(label) {
        lastWinnerLabel = String(label || "").trim();
        updateWinnerDisplay();
    }

    function clearWinnerDisplay() {
        lastWinnerLabel = "";
        updateWinnerDisplay();
    }

    function startPendingSpin(previousSegments) {
        isAnimating = true;
        renderState();
        pendingSpinStart = performance.now();

        const animate = (now) => {
            if (!isAnimating || !Array.isArray(previousSegments) || previousSegments.length < 2) {
                pendingSpinFrame = null;
                return;
            }

            const elapsed = now - pendingSpinStart;
            rotation += 0.015 + Math.min(0.02, elapsed / 16000);
            drawWheel(previousSegments);
            pendingSpinFrame = requestAnimationFrame(animate);
        };

        pendingSpinFrame = requestAnimationFrame(animate);
    }

    function stopPendingSpin() {
        if (pendingSpinFrame !== null) {
            cancelAnimationFrame(pendingSpinFrame);
            pendingSpinFrame = null;
        }
    }

    function animateToSelected(selectedStudent, previousSegments) {
        const index = previousSegments.findIndex((student) => Number(student.id) === Number(selectedStudent.id));

        if (index === -1) {
            isAnimating = false;
            renderState();
            return;
        }

        const arc = (Math.PI * 2) / previousSegments.length;
        const centerAngle = index * arc + arc / 2;
        const targetRotation = -Math.PI / 2 - centerAngle;
        const turns = Math.PI * 8;
        const start = rotation;
        const end = targetRotation + turns;
        const duration = 1800;
        const startTime = performance.now();

        const animate = (now) => {
            const progress = Math.min((now - startTime) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            rotation = start + (end - start) * eased;
            drawWheel(previousSegments);

            if (progress < 1) {
                requestAnimationFrame(animate);
                return;
            }

            rotation = targetRotation;
            isAnimating = false;
            renderState();
            showWinnerDisplay(selectedStudent.label);
            setFeedback("", "success");
        };

        requestAnimationFrame(animate);
    }

    // ============================================================
    // Handlers — act on screen at once, then queue for the server
    // ============================================================
    function handleSpin() {
        if (isAnimating || state.pending_evaluation || !state.stats.can_spin) return;

        const remaining = currentSegments();
        if (remaining.length === 0) return;

        const chosen = remaining[Math.floor(Math.random() * remaining.length)];
        const previousSegments = [...remaining];

        clearWinnerDisplay();
        startPendingSpin(previousSegments);

        Queue.add({
            token: newToken("spin"),
            type: "spin",
            studentId: chosen.id,
            studentLabel: chosen.label,
            ts: Date.now(),
        });

        setTimeout(function () {
            stopPendingSpin();
            animateToSelected(chosen, previousSegments);
        }, 400);
    }

    function handleEvaluation(score) {
        const pending = state.pending_evaluation;
        if (!pending || isAnimating) return;

        clearWinnerDisplay();
        Queue.add({
            token: newToken("eval"),
            type: "evaluate",
            spinToken: pending.spinToken || null,
            evaluationId: pending.id || null,
            studentId: pending.student.id,
            studentLabel: pending.student.label,
            score: score,
            ts: Date.now(),
        });
        setFeedback(`Evaluación "${score}" registrada para ${pending.student.label}.`, "success");
    }

    function handleReset() {
        if (isAnimating || !state.stats.can_reset) return;

        const confirmed = window.confirm(
            "Se creará una nueva ronda aunque todavía queden alumnos pendientes. ¿Quieres continuar?"
        );
        if (!confirmed) return;

        clearWinnerDisplay();
        Queue.add({
            token: newToken("reset"),
            type: "reset",
            ts: Date.now(),
        });
        setFeedback("Nueva ronda creada.", "success");
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    }

    // ============================================================
    // Wiring
    // ============================================================
    spinButton.addEventListener("click", handleSpin);
    resetButton.addEventListener("click", handleReset);

    evaluationButtons.forEach((button) => {
        button.addEventListener("click", () => handleEvaluation(button.dataset.score));
    });

    attendanceStartButton?.addEventListener("click", () => Attendance.start());
    attendanceFinishButton?.addEventListener("click", () => Attendance.finish());

    document.querySelectorAll("[data-sync-retry]").forEach((button) => button.addEventListener("click", retryAll));
    document.querySelectorAll("[data-sync-backup]").forEach((button) => button.addEventListener("click", () => SyncUI.downloadBackup()));
    document.getElementById("sync-conflicts-clear")?.addEventListener("click", () => Conflicts.clear());

    window.addEventListener("online", retryAll);
    document.addEventListener("visibilitychange", retryAll);
    setInterval(function () {
        retryAll();
        SyncUI.update();
    }, 30000);

    // Another tab of the same class changed the queue.
    window.addEventListener("storage", function (event) {
        if (event.key === Queue.storageKey) Queue.reloadFromStorage();
    });

    window.addEventListener("beforeunload", function (event) {
        if (hasUnsavedWork()) {
            event.preventDefault();
            event.returnValue = "";
        }
    });

    window.addEventListener("resize", () => {
        if (!isAnimating) drawWheel();
        updateWinnerDisplay();
    });

    rebase();
    renderState();
    retryAll();
}
