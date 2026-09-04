/**
 * Every alert, confirmation and toast in Agora.
 *
 * SweetAlert2 is loaded on first use rather than on every page. It is 70 KB
 * that the sign-in screen and most read-only screens never need, and the delay
 * before a dialog appears is imperceptible next to the click that asked for it.
 *
 * Nothing calls window.alert, confirm or prompt — those block the page, cannot
 * be themed, and look like a browser failure rather than the system asking a
 * question.
 */
let swal = null;

async function sweet() {
    if (!swal) {
        const module = await import('sweetalert2');
        swal = module.default;
    }

    return swal;
}

/** Shared look, so a dialog is Agora's rather than the library's. */
function theme(options = {}) {
    return {
        buttonsStyling: false,
        customClass: {
            popup: 'ag-dialog',
            title: 'ag-dialog-title',
            htmlContainer: 'ag-dialog-body',
            confirmButton: 'btn-primary',
            cancelButton: 'btn-ghost',
        },
        ...options,
    };
}

export async function alert(title, text = '') {
    const s = await sweet();

    return s.fire(theme({ title, text, icon: 'info', confirmButtonText: 'OK' }));
}

export async function warn(title, text = '') {
    const s = await sweet();

    return s.fire(theme({ title, text, icon: 'warning', confirmButtonText: 'OK' }));
}

export async function error(title, text = '') {
    const s = await sweet();

    return s.fire(theme({ title, text, icon: 'error', confirmButtonText: 'OK' }));
}

/**
 * Ask before doing something that cannot be taken back.
 *
 * The confirm button says what will happen — "Delete", "Post", "Reverse" —
 * rather than "Yes", so the last thing read before committing is the action
 * itself.
 */
export async function confirm(title, { text = '', action = 'Continue', danger = false } = {}) {
    const s = await sweet();

    const result = await s.fire(theme({
        title,
        text,
        icon: danger ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: action,
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: danger,
    }));

    return result.isConfirmed;
}

/** A short confirmation of something that already happened. */
export async function toast(title, icon = 'success') {
    const s = await sweet();

    return s.fire(theme({
        title,
        icon,
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 2600,
        timerProgressBar: true,
        customClass: { popup: 'ag-toast' },
    }));
}

export default { alert, warn, error, confirm, toast };
