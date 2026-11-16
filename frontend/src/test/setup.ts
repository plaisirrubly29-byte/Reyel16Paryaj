import '@testing-library/jest-dom/vitest';
import { cleanup, configure } from '@testing-library/react';
import { afterEach } from 'vitest';

afterEach(() => cleanup());

// Pages chargées à la demande (V2-E05) : leur premier chargement peut dépasser 1 s quand toute la suite tourne en parallèle.
configure({ asyncUtilTimeout: 3000 });

// jsdom n'implémente pas le défilement de la fenêtre.
window.scrollTo = () => {};
