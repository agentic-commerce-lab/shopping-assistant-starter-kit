/*
 * The creature's face, as DOM.
 *
 * The orb and the panel's avatar are rendered by Twig; the thinking indicator is built here, at the
 * moment a turn starts. Both spell the same five elements, and this is the client-side half — see
 * `views/storefront/component/assistant/face.html.twig` for the server-side one.
 *
 * Why each visible shape sits inside a wrapper of fixed size: an expression that widens an eye or
 * turns it into an arc would shift its own centre if the shape were the positioned element, and the
 * eyes would drift apart every time the creature laughed. The wrapper holds the anchor; the shape
 * inside it is free to become anything.
 */

/**
 * @returns {HTMLElement} an `aria-hidden` face, ready to append to anything with a `--swag-assistant-unit`
 */
export function buildFace() {
    const face = document.createElement('span');
    face.className = 'swag-assistant-face';
    // The face is decoration on top of a control that already has an accessible name. Announcing
    // three empty spans to a screen reader adds nothing and interrupts something.
    face.setAttribute('aria-hidden', 'true');

    face.appendChild(eye('left'));
    face.appendChild(eye('right'));
    face.appendChild(mouth());

    return face;
}

function eye(side) {
    const socket = document.createElement('span');
    socket.className = `swag-assistant-face__eye swag-assistant-face__eye--${side}`;

    const pupil = document.createElement('i');
    pupil.className = 'swag-assistant-face__pupil';
    socket.appendChild(pupil);

    return socket;
}

function mouth() {
    const socket = document.createElement('span');
    socket.className = 'swag-assistant-face__mouth';

    const lip = document.createElement('i');
    lip.className = 'swag-assistant-face__lip';
    socket.appendChild(lip);

    return socket;
}
