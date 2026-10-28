(() => {
    const root = document.querySelector('[data-editable-report]');
    if (!root) {
        return;
    }

    const form = root.querySelector('#report-form');

    const toggle = document.querySelector('[data-edit-toggle]');
    const saveBar = form;
    const counter = form.querySelector('[data-dirty-count]');
    const inputs = Array.from(root.querySelectorAll('.cell-input'));
    const deleteForm = document.getElementById('column-delete-form');

    const isDirty = (input) => input.value !== input.dataset.initial;
    const dirtyCount = () => inputs.filter(isDirty).length;

    const refresh = () => {
        const count = dirtyCount();
        saveBar.hidden = !root.classList.contains('is-editing');
        counter.textContent = count > 0 ? `${count} ${counter.dataset.label}` : '';
        form.querySelector('[data-save-submit]').disabled = count === 0;
    };

    const setEditing = (editing) => {
        root.classList.toggle('is-editing', editing);
        if (toggle) {
            toggle.textContent = editing ? toggle.dataset.labelOff : toggle.dataset.labelOn;
            toggle.classList.toggle('secondary-button', editing);
            toggle.classList.toggle('primary-button', !editing);
        }
        refresh();
    };

    const reset = () => {
        inputs.forEach((input) => {
            input.value = input.dataset.initial;
            input.closest('td').classList.remove('is-dirty');
        });
    };

    inputs.forEach((input) => {
        input.addEventListener('change', () => {
            input.closest('td').classList.toggle('is-dirty', isDirty(input));
            refresh();
        });
    });

    toggle?.addEventListener('click', () => {
        if (root.classList.contains('is-editing') && dirtyCount() > 0 && !window.confirm('Hay cambios sin guardar. ¿Descartarlos?')) {
            return;
        }
        if (root.classList.contains('is-editing')) {
            reset();
        }
        setEditing(!root.classList.contains('is-editing'));
    });

    form.querySelector('[data-edit-cancel]').addEventListener('click', () => {
        reset();
        setEditing(false);
    });


    root.querySelectorAll('.column-delete').forEach((button) => {
        button.addEventListener('click', () => {
            if (!window.confirm(button.dataset.confirm)) {
                return;
            }
            deleteForm.elements.cycle_number.value = button.dataset.cycleNumber || '';
            deleteForm.elements.attendance_date.value = button.dataset.attendanceDate || '';
            deleteForm.submit();
        });
    });

    window.addEventListener('beforeunload', (event) => {
        if (dirtyCount() > 0) {
            event.preventDefault();
        }
    });

    // Only changed cells are posted; the page reloads after saving, so the unload guard is cleared.
    form.addEventListener('submit', () => {
        inputs.forEach((input) => {
            if (isDirty(input)) {
                input.dataset.initial = input.value;
            } else {
                input.disabled = true;
            }
        });
    });

    refresh();
})();
