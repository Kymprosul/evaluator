const app = document.getElementById("classroom-app");

if (app) {
    const rouletteContainer = document.getElementById("roulette-container");
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

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";
    const spinUrl = app.dataset.spinUrl;
    const evaluateUrl = app.dataset.evaluateUrl;
    const resetUrl = app.dataset.resetUrl;
    const attendanceUrl = app.dataset.attendanceUrl;

    let state = JSON.parse(app.dataset.initialState);
    let rotation = 0;
    let isAnimating = false;
    let pendingSpinFrame = null;
    let pendingSpinStart = 0;
    let lastWinnerLabel = state.pending_evaluation?.student?.label || "";
    let selectedAttendance = new Set(state.attendance?.present_student_ids || []);
    let currentAttendanceDate = state.attendance?.date || "";
    let attendanceAudioContext = null;

    // ============================================================
    // AttendanceSync module — auto-save attendance with offline support
    // ============================================================
    const AttendanceSync = (function () {
        const MAX_RETRIES = 3;
        const BACKOFF_MS = [2000, 4000, 8000];
        const IDLE_TIMEOUT = 2500;
        const MAX_DAYS = 7;

        let idleTimer = null;
        let retryCount = 0;
        let isSyncing = false;

        function getIndicator() {
            return document.getElementById("sync-indicator");
        }

        function getRetryBtn() {
            return document.getElementById("retry-sync-btn");
        }

        function setIndicatorState(state) {
            const el = getIndicator();
            if (!el) return;
            el.className = "";
            if (state === "pending") el.classList.add("pending");
            else if (state === "synced") el.classList.add("synced");
            else if (state === "error") el.classList.add("error");
        }

        function showRetryBtn(show) {
            const btn = getRetryBtn();
            if (btn) {
                btn.style.display = show ? "inline-block" : "none";
            }
        }

        function todayStr() {
            return new Date().toISOString().slice(0, 10);
        }

        function storageKey(classId, date) {
            return "eval_attendance_" + classId + "_" + date;
        }

        function readLocal(classId, date) {
            try {
                const raw = localStorage.getItem(storageKey(classId, date));
                if (!raw) return null;
                return JSON.parse(raw);
            } catch {
                return null;
            }
        }

        function writeLocal(classId, date, ids, synced) {
            try {
                const data = { ids: Array.from(ids), synced: synced || false, ts: Date.now() };
                localStorage.setItem(storageKey(classId, date), JSON.stringify(data));
            } catch {
                // localStorage full or disabled — silently ignore
            }
        }

        function cleanupOldKeys(classId) {
            const today = todayStr();
            const toDelete = [];
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key && key.startsWith("eval_attendance_" + classId + "_")) {
                    const datePart = key.replace("eval_attendance_" + classId + "_", "");
                    if (datePart !== today) {
                        // Check if older than MAX_DAYS
                        const diff = Math.floor((new Date(today) - new Date(datePart)) / 86400000);
                        if (diff > MAX_DAYS) {
                            toDelete.push(key);
                        }
                    }
                }
            }
            toDelete.forEach(function (k) { localStorage.removeItem(k); });
        }

        function resetIdleTimer() {
            clearTimeout(idleTimer);
            idleTimer = setTimeout(function () {
                syncToServer();
            }, IDLE_TIMEOUT);
        }

        function syncToServer() {
            if (isSyncing) return;
            if (!attendanceUrl) return;

            const classId = state.class?.id;
            if (!classId) return;

            const presentIds = Array.from(selectedAttendance);
            const date = state.attendance?.date || todayStr();

            // Write to localStorage as pending
            writeLocal(classId, date, presentIds, false);

            isSyncing = true;
            setIndicatorState("pending");
            showRetryBtn(false);

            function doSend(attempt) {
                const payload = new URLSearchParams();
                payload.append("class_id", String(classId));
                payload.append("attendance_date", String(date));
                presentIds.forEach(function (studentId) {
                    payload.append("present_student_ids[]", String(studentId));
                });

                fetch(attendanceUrl, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                        "X-CSRF-Token": csrfToken,
                    },
                    body: payload,
                })
                    .then(function (response) { return response.json().then(function (result) { return { response: response, result: result }; }); })
                    .then(function (data) {
                        if (!data.response.ok || !data.result.ok) {
                            throw new Error(data.result.message || "No se pudo guardar la asistencia.");
                        }
                        return data.result.data;
                    })
                    .then(function (data) {
                        // Success
                        retryCount = 0;
                        isSyncing = false;
                        setIndicatorState("synced");
                        showRetryBtn(false);

                        // Mark as synced in localStorage
                        writeLocal(classId, date, presentIds, true);

                        // Update in-memory state
                        if (data && data.state) {
                            applyIncomingState(data.state);
                            selectedAttendance = new Set(state.attendance?.present_student_ids || []);
                            currentAttendanceDate = state.attendance?.date || "";
                            renderState();
                        }

                        if (data && data.message) {
                            setFeedback(data.message, data.date_changed ? "info" : "success");
                        }
                    })
                    .catch(function (error) {
                        if (attempt < MAX_RETRIES) {
                            const delay = BACKOFF_MS[attempt];
                            setTimeout(function () {
                                doSend(attempt + 1);
                            }, delay);
                        } else {
                            // All retries exhausted
                            isSyncing = false;
                            setIndicatorState("error");
                            showRetryBtn(true);
                        }
                    });
            }

            doSend(0);
        }

        function onAttendanceToggle() {
            const classId = state.class?.id;
            if (!classId) return;
            const date = state.attendance?.date || todayStr();

            // Save to localStorage
            writeLocal(classId, date, selectedAttendance, false);

            // Set indicator to pending
            setIndicatorState("pending");

            // Reset idle timer
            resetIdleTimer();
        }

        function recoverOnLoad() {
            const classId = state.class?.id;
            if (!classId) return;

            cleanupOldKeys(classId);

            const today = todayStr();
            const key = storageKey(classId, today);
            const data = readLocal(classId, today);

            if (data && data.ids && data.ids.length > 0 && !data.synced) {
                // Restore the selection in memory
                data.ids.forEach(function (id) { selectedAttendance.add(id); });
                renderState();

                // Sync immediately
                syncToServer();
            }
        }

        function attachListeners() {
            // Listen for clicks on attendance buttons (delegated)
            if (attendanceContainer) {
                attendanceContainer.addEventListener("click", function (e) {
                    if (e.target.classList && e.target.classList.contains("attendance-student-button")) {
                        onAttendanceToggle();
                    }
                });
                attendanceContainer.addEventListener("touchend", function (e) {
                    if (e.target.classList && e.target.classList.contains("attendance-student-button")) {
                        onAttendanceToggle();
                    }
                });
            }

            // Visibility change: sync immediately when page is hidden
            document.addEventListener("visibilitychange", function () {
                if (document.visibilityState === "hidden") {
                    syncToServer();
                }
            });

            // Retry button
            const retryBtn = getRetryBtn();
            if (retryBtn) {
                retryBtn.addEventListener("click", function () {
                    retryCount = 0;
                    syncToServer();
                });
            }
        }

        function init() {
            attachListeners();
            // Delay recovery until after initial render
            setTimeout(recoverOnLoad, 100);
        }

        return {
            init: init,
            onToggle: onAttendanceToggle,
            sync: syncToServer,
        };
    })();

    // ============================================================
    // EvaluationSync module — local-first spin/evaluate with queue
    // ============================================================
    const EvaluationSync = (function () {
        const MAX_RETRIES = 3;
        const BACKOFF_MS = [2000, 4000, 8000];
        const QUEUE_KEY_PREFIX = "eval_queue_";
        const ID_MAP_PREFIX = "eval_id_map_";

        let isDraining = false;
        let drainTimer = null;
        let idMap = {}; // tempId → realId mapping

        // --- localStorage helpers ---

        function queueKey(classId) {
            return QUEUE_KEY_PREFIX + classId;
        }

        function idMapKey(classId) {
            return ID_MAP_PREFIX + classId;
        }

        function readQueue(classId) {
            try {
                const raw = localStorage.getItem(queueKey(classId));
                return raw ? JSON.parse(raw) : [];
            } catch {
                return [];
            }
        }

        function writeQueue(classId, queue) {
            try {
                localStorage.setItem(queueKey(classId), JSON.stringify(queue));
            } catch {
                // localStorage full — silently ignore
            }
        }

        function readIdMap(classId) {
            try {
                const raw = localStorage.getItem(idMapKey(classId));
                return raw ? JSON.parse(raw) : {};
            } catch {
                return {};
            }
        }

        function writeIdMap(classId, map) {
            try {
                localStorage.setItem(idMapKey(classId), JSON.stringify(map));
            } catch {
                // silently ignore
            }
        }

        function generateTempId(prefix) {
            const ts = Date.now();
            const rand = Math.random().toString(36).slice(2, 10);
            return prefix + "_" + ts + "_" + rand;
        }

        // --- Indicator helpers ---

        function getSpinIndicator() {
            return document.getElementById("eval-sync-indicator");
        }

        function getEvalIndicator() {
            return document.getElementById("eval-score-indicator");
        }

        function getRetryBtn() {
            return document.getElementById("eval-retry-btn");
        }

        function setIndicatorState(el, syncState) {
            if (!el) return;
            el.className = el.className.replace(/\b(pending|synced|error)\b/g, "").trim();
            if (syncState) el.classList.add(syncState);
        }

        function updateIndicators() {
            const classId = state.class?.id;
            if (!classId) return;

            const queue = readQueue(classId);
            const hasPending = queue.length > 0;
            const hasError = queue.some(function (a) { return a.retryCount >= MAX_RETRIES; });

            const spinInd = getSpinIndicator();
            const evalInd = getEvalIndicator();
            const retryBtn = getRetryBtn();

            if (hasError) {
                setIndicatorState(spinInd, "error");
                setIndicatorState(evalInd, "error");
                if (retryBtn) retryBtn.style.display = "inline-block";
            } else if (hasPending) {
                setIndicatorState(spinInd, "pending");
                setIndicatorState(evalInd, "pending");
                if (retryBtn) retryBtn.style.display = "none";
            } else {
                setIndicatorState(spinInd, "synced");
                setIndicatorState(evalInd, "synced");
                if (retryBtn) retryBtn.style.display = "none";
            }
        }

        // --- Queue operations ---

        function enqueue(classId, action) {
            const queue = readQueue(classId);
            queue.push(action);
            writeQueue(classId, queue);
            updateIndicators();
            scheduleDrain(classId);
        }

        function removeAction(classId, tempId) {
            const queue = readQueue(classId);
            const filtered = queue.filter(function (a) { return a.tempId !== tempId; });
            writeQueue(classId, filtered);
        }

        function updateActionEvaluationId(classId, oldEvalId, newEvalId) {
            const queue = readQueue(classId);
            queue.forEach(function (action) {
                if (action.type === "evaluate" && action.evaluationId === oldEvalId) {
                    action.evaluationId = newEvalId;
                }
            });
            writeQueue(classId, queue);
        }

        // --- ID mapping ---

        function mapTempToReal(classId, tempId, realId) {
            idMap = readIdMap(classId);
            idMap[tempId] = realId;
            writeIdMap(classId, idMap);
        }

        function resolveId(classId, id) {
            if (typeof id === "number") return id;
            if (typeof id === "string" && id.startsWith("spin_")) {
                idMap = readIdMap(classId);
                return idMap[id] || null;
            }
            return id;
        }

        // --- Network ---

        function postAction(url, payload) {
            return fetch(url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                    "X-CSRF-Token": csrfToken,
                },
                body: new URLSearchParams(payload),
            }).then(function (response) {
                return response.json().then(function (result) {
                    return { response: response, result: result };
                });
            }).then(function (data) {
                if (!data.response.ok || !data.result.ok) {
                    throw new Error(data.result.message || "Error del servidor.");
                }
                return data.result.data;
            });
        }

        // --- Drain (process queue) ---

        function drain(classId) {
            if (isDraining) return;
            if (!classId) classId = state.class?.id;
            if (!classId) return;

            const queue = readQueue(classId);
            if (queue.length === 0) {
                updateIndicators();
                return;
            }

            isDraining = true;
            drainOne(classId, 0);
        }

        function drainOne(classId, index) {
            const queue = readQueue(classId);
            if (index >= queue.length) {
                isDraining = false;
                updateIndicators();
                return;
            }

            const action = queue[index];

            // Skip confirmed actions
            if (action.status === "confirmed") {
                removeAction(classId, action.tempId);
                isDraining = false;
                drain(classId);
                return;
            }

            // Max retries exhausted
            if (action.retryCount >= MAX_RETRIES) {
                isDraining = false;
                updateIndicators();
                return;
            }

            // Build request based on type
            let url, payload;

            if (action.type === "spin") {
                url = spinUrl;
                payload = {
                    class_id: String(classId),
                    student_id: String(action.studentId),
                };
            } else if (action.type === "evaluate") {
                url = evaluateUrl;
                var evalId = resolveId(classId, action.evaluationId);

                // If we still can't resolve the ID (spin not confirmed yet), postpone
                if (evalId === null) {
                    setTimeout(function () {
                        drainOne(classId, index);
                    }, 500);
                    return;
                }

                payload = {
                    class_id: String(classId),
                    evaluation_id: String(evalId),
                    score: action.score,
                };
            } else if (action.type === "reset") {
                url = resetUrl;
                payload = {
                    class_id: String(classId),
                };
            } else {
                // Unknown type — remove and continue
                removeAction(classId, action.tempId);
                isDraining = false;
                drain(classId);
                return;
            }

            postAction(url, payload)
                .then(function (data) {
                    // Success — remove from queue
                    removeAction(classId, action.tempId);

                    // Map tempId to real ID if this was a spin
                    if (action.type === "spin" && data.selected && data.selected.id) {
                        mapTempToReal(classId, action.tempId, data.selected.id);
                        // Update any pending evaluates that reference this tempId
                        updateActionEvaluationId(classId, action.tempId, data.selected.id);
                    }

                    // Apply server state for reconciliation
                    if (data.state) {
                        applyIncomingState(data.state);
                        renderState();
                    }

                    // Continue draining
                    isDraining = false;
                    drain(classId);
                })
                .catch(function (error) {
                    // Business error (server rejected) — reconcile and remove
                    if (error.message && !error.message.includes("Failed to fetch") && !error.message.includes("NetworkError")) {
                        removeAction(classId, action.tempId);
                        // Try to get fresh state from server
                        postAction(spinUrl, { class_id: String(classId), _peek: "1" })
                            .catch(function () { /* ignore */ });
                        isDraining = false;
                        setFeedback("Se actualizó la información. " + error.message, "info");
                        drain(classId);
                        return;
                    }

                    // Network error — retry with backoff
                    action.retryCount = (action.retryCount || 0) + 1;
                    var q = readQueue(classId);
                    var idx = q.findIndex(function (a) { return a.tempId === action.tempId; });
                    if (idx !== -1) {
                        q[idx].retryCount = action.retryCount;
                        writeQueue(classId, q);
                    }

                    if (action.retryCount < MAX_RETRIES) {
                        var delay = BACKOFF_MS[action.retryCount - 1];
                        setTimeout(function () {
                            isDraining = false;
                            drainOne(classId, index);
                        }, delay);
                    } else {
                        isDraining = false;
                        updateIndicators();
                        setFeedback("Error de conexión. Pulsa 'Reintentar' para sincronizar.", "error");
                    }
                });
        }

        function scheduleDrain(classId) {
            clearTimeout(drainTimer);
            drainTimer = setTimeout(function () {
                drain(classId);
            }, 100);
        }

        function forceSync() {
            var classId = state.class?.id;
            if (!classId) return;

            // Reset retry counts
            var queue = readQueue(classId);
            queue.forEach(function (a) {
                if (a.retryCount >= MAX_RETRIES) {
                    a.retryCount = 0;
                }
            });
            writeQueue(classId, queue);

            isDraining = false;
            drain(classId);
        }

        // --- Listeners ---

        function attachListeners() {
            // Online event — retry when connection restored
            window.addEventListener("online", function () {
                forceSync();
            });

            // Visibility change — sync when tab hidden
            document.addEventListener("visibilitychange", function () {
                if (document.visibilityState === "hidden") {
                    forceSync();
                }
            });

            // Retry button
            var retryBtn = getRetryBtn();
            if (retryBtn) {
                retryBtn.addEventListener("click", function () {
                    forceSync();
                });
            }
        }

        // --- Init ---

        function init() {
            var classId = state.class?.id;
            if (!classId) return;

            idMap = readIdMap(classId);
            attachListeners();

            // Drain queue from previous session
            setTimeout(function () {
                drain(classId);
            }, 200);
        }

        function getStatus() {
            var classId = state.class?.id;
            if (!classId) return { pending: 0, syncing: false };
            var queue = readQueue(classId);
            return {
                pending: queue.length,
                syncing: isDraining,
            };
        }

        return {
            init: init,
            enqueue: enqueue,
            drain: drain,
            forceSync: forceSync,
            getStatus: getStatus,
            generateTempId: generateTempId,
        };
    })();

    function applyIncomingState(incomingState) {
        const nextAttendanceDate = incomingState.attendance?.date || "";
        if (nextAttendanceDate !== currentAttendanceDate) {
            selectedAttendance = new Set(incomingState.attendance?.present_student_ids || []);
            currentAttendanceDate = nextAttendanceDate;
        }

        state = incomingState;
    }

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

    function drawWheel() {
        const size = Math.min(canvas.clientWidth || canvas.width, canvas.clientHeight || canvas.height || canvas.width);
        canvas.width = size;
        canvas.height = size;

        context.clearRect(0, 0, canvas.width, canvas.height);

        const segments = currentSegments();
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

        const attendanceDateEl = document.getElementById("attendance-date");
        if (attendanceDateEl) {
            attendanceDateEl.textContent = currentAttendanceDate || "";
        }

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

            if (selectedAttendance.has(Number(student.id))) {
                button.classList.add("is-present");
            }

            button.addEventListener("click", () => {
                const studentId = Number(student.id);
                if (selectedAttendance.has(studentId)) {
                    selectedAttendance.delete(studentId);
                    button.classList.remove("is-present");
                    playAttendanceBling(false);
                    return;
                }

                selectedAttendance.add(studentId);
                button.classList.add("is-present");
                playAttendanceBling(true);
            });

            attendanceContainer.appendChild(button);
        });
    }

    function renderState() {
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
        drawWheel();
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
            drawWheelWithSegments(previousSegments);
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

    async function postJson(url, payload) {
        const response = await fetch(url, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                "X-CSRF-Token": csrfToken,
            },
            body: new URLSearchParams(payload),
        });

        const result = await response.json();
        if (!response.ok || !result.ok) {
            throw new Error(result.message || "No se pudo completar la operación.");
        }

        return result.data;
    }

    function animateToSelected(selectedStudent, previousSegments, incomingState) {
        const index = previousSegments.findIndex((student) => Number(student.id) === Number(selectedStudent.id));
        applyIncomingState(incomingState);

        if (index === -1) {
            renderState();
            return;
        }

        isAnimating = true;
        renderState();

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
            drawWheelWithSegments(previousSegments);

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

    function drawWheelWithSegments(segments) {
        const original = state.remaining_students;
        state.remaining_students = segments;
        drawWheel();
        state.remaining_students = original;
    }

    // ============================================================
    // LOCAL-FIRST HANDLERS
    // ============================================================

    function handleSpin() {
        if (isAnimating) return;
        if (state.pending_evaluation !== null) return;
        if (!state.stats.can_spin) return;

        const remaining = currentSegments();
        if (remaining.length === 0) return;

        const classId = state.class.id;

        // 1. Pick student locally
        const chosen = remaining[Math.floor(Math.random() * remaining.length)];
        const tempId = EvaluationSync.generateTempId("spin");

        // 2. Update state immediately
        state.pending_evaluation = {
            id: tempId,
            score: null,
            selected_at: new Date().toISOString(),
            evaluated_at: null,
            evaluated_by: null,
            student: { id: chosen.id, code: chosen.code, name: chosen.name, label: chosen.label },
            tempId: tempId,
        };
        state.remaining_students = remaining.filter(function (s) { return Number(s.id) !== Number(chosen.id); });
        state.stats.remaining_students = state.remaining_students.length;
        state.stats.can_spin = false;
        state.stats.can_reset = false;

        // 3. Animate immediately
        clearWinnerDisplay();
        const previousSegments = [...remaining];
        startPendingSpin(previousSegments);

        setTimeout(function () {
            stopPendingSpin();
            animateToSelected(chosen, previousSegments, state);
        }, 400);

        // 4. Enqueue for server sync
        EvaluationSync.enqueue(classId, {
            tempId: tempId,
            type: "spin",
            classId: classId,
            studentId: chosen.id,
            timestamp: Date.now(),
            retryCount: 0,
        });
    }

    function handleEvaluation(score) {
        if (!state.pending_evaluation || isAnimating) return;

        const classId = state.class.id;
        const evaluation = state.pending_evaluation;

        // 1. Update state immediately
        state.recent_evaluations.unshift({
            student: evaluation.student.label,
            score: score,
            evaluated_at: new Date().toISOString(),
        });
        if (state.recent_evaluations.length > 8) {
            state.recent_evaluations.pop();
        }

        state.pending_evaluation = null;
        state.stats.evaluated_students += 1;

        // Check if this was the last student
        if (state.remaining_students.length === 0) {
            state.cycle.status = "completed";
            state.stats.can_spin = false;
            state.stats.can_reset = true;
        } else {
            state.stats.can_spin = true;
            state.stats.can_reset = true;
        }

        // 2. Update UI immediately
        clearWinnerDisplay();
        renderState();
        setFeedback(`Evaluación guardada con "${score}".`, "success");

        // 3. Enqueue for server sync
        EvaluationSync.enqueue(classId, {
            tempId: EvaluationSync.generateTempId("eval"),
            type: "evaluate",
            classId: classId,
            evaluationId: evaluation.id,
            studentId: evaluation.student.id,
            score: score,
            timestamp: Date.now(),
            retryCount: 0,
        });
    }

    function handleReset() {
        if (isAnimating) return;

        const confirmed = window.confirm(
            "Se creará una nueva ronda aunque todavía queden alumnos pendientes. ¿Quieres continuar?"
        );
        if (!confirmed) return;

        const classId = state.class.id;

        // Optimistic: disable buttons while syncing
        state.stats.can_reset = false;
        state.stats.can_spin = false;
        renderState();
        setFeedback("Creando nueva ronda...", "success");

        // Enqueue for server sync — state will be reconciled on success
        EvaluationSync.enqueue(classId, {
            tempId: EvaluationSync.generateTempId("reset"),
            type: "reset",
            classId: classId,
            timestamp: Date.now(),
            retryCount: 0,
        });
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    }

    spinButton.addEventListener("click", handleSpin);
    resetButton.addEventListener("click", handleReset);

    evaluationButtons.forEach((button) => {
        button.addEventListener("click", () => handleEvaluation(button.dataset.score));
    });

    window.addEventListener("resize", () => {
        drawWheel();
        updateWinnerDisplay();
    });

    renderState();

    // Initialize auto-save attendance
    AttendanceSync.init();

    // Initialize evaluation sync (local-first)
    EvaluationSync.init();
}
