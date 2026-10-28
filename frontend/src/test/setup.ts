import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

afterEach(() => cleanup());

// jsdom n'implémente pas le défilement de la fenêtre.
window.scrollTo = () => {};
