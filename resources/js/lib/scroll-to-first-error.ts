/**
 * After a server-side validation error, bring the first invalid field into
 * view and focus it. Generic on purpose: it relies on the `aria-invalid` /
 * `data-error` attributes the form fields already set, so it needs no
 * field→element map and works for inputs, comboboxes and pickers alike.
 *
 * Deferred to the next animation frame so React has applied the error
 * attributes to the DOM before we query for them.
 */
export function scrollToFirstError(): void {
    requestAnimationFrame(() => {
        const target = document.querySelector<HTMLElement>(
            '[aria-invalid="true"], [data-error="true"]',
        );
        if (!target) {
            return;
        }

        target.scrollIntoView({ behavior: 'smooth', block: 'center' });

        const focusable = target.matches(
            'input, button, select, textarea, [tabindex]',
        )
            ? target
            : target.querySelector<HTMLElement>(
                  'input, button, select, textarea, [tabindex]',
              );

        focusable?.focus({ preventScroll: true });
    });
}
