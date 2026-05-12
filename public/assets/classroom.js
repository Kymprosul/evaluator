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
    const saveAttendanceButton = document.getElementById("save-attendance-button");

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
            if (saveAttendanceButton) {
                saveAttendanceButton.disabled = true;
            }
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

        if (saveAttendanceButton) {
            saveAttendanceButton.disabled = false;
        }
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

    async function handleSpin() {
        if (isAnimating) {
            return;
        }

        clearWinnerDisplay();
        const previousSegments = [...currentSegments()];
        startPendingSpin(previousSegments);

        try {
            setFeedback("Girando ruleta...");
            const data = await postJson(spinUrl, { class_id: state.class.id });
            stopPendingSpin();
            animateToSelected(data.selected.student, previousSegments, data.state);
        } catch (error) {
            stopPendingSpin();
            isAnimating = false;
            renderState();
            setFeedback(error.message, "error");
        }
    }

    async function handleEvaluation(score) {
        if (!state.pending_evaluation || isAnimating) {
            return;
        }

        try {
            const data = await postJson(evaluateUrl, {
                class_id: state.class.id,
                evaluation_id: state.pending_evaluation.id,
                score,
            });

            applyIncomingState(data.state);
            renderState();
            setFeedback(`Evaluación guardada con "${score}".`, "success");
        } catch (error) {
            setFeedback(error.message, "error");
        }
    }

    async function handleReset() {
        if (isAnimating) {
            return;
        }

        const confirmed = window.confirm(
            "Se creará una nueva ronda aunque todavía queden alumnos pendientes. ¿Quieres continuar?"
        );
        if (!confirmed) {
            return;
        }

        try {
            const data = await postJson(resetUrl, { class_id: state.class.id });
            applyIncomingState(data.state);
            rotation = 0;
            renderState();
            setFeedback(data.message, "success");
        } catch (error) {
            setFeedback(error.message, "error");
        }
    }

    async function handleSaveAttendance() {
        if (!saveAttendanceButton) {
            return;
        }

        saveAttendanceButton.disabled = true;

        try {
            const payload = new URLSearchParams();
            payload.append("class_id", String(state.class.id));
            payload.append("attendance_date", String(state.attendance?.date || ""));
            Array.from(selectedAttendance).forEach((studentId) => {
                payload.append("present_student_ids[]", String(studentId));
            });

            const response = await fetch(attendanceUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                    "X-CSRF-Token": csrfToken,
                },
                body: payload,
            });

            const result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.message || "No se pudo guardar la asistencia.");
            }

            const data = result.data;
            applyIncomingState(data.state);
            selectedAttendance = new Set(state.attendance?.present_student_ids || []);
            currentAttendanceDate = state.attendance?.date || "";
            renderState();
            setFeedback(data.message || "Asistencia guardada.", data.date_changed ? "info" : "success");
        } catch (error) {
            setFeedback(error.message, "error");
        } finally {
            saveAttendanceButton.disabled = false;
        }
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

    if (saveAttendanceButton) {
        saveAttendanceButton.addEventListener("click", handleSaveAttendance);
    }

    window.addEventListener("resize", () => {
        drawWheel();
        updateWinnerDisplay();
    });

    renderState();
}
