import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Modal } from './Modal';

// F12: the modal is a capped-height flex column - body scrolls, footer is
// pinned. This smoke test only asserts the structural contract that matters:
// children render and the footer content renders alongside them.
it('renders children and footer together', () => {
  render(
    <Modal open onClose={() => {}} title="t" footer={<button>Book</button>}>
      body
    </Modal>,
  );

  expect(screen.getByText('body')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Book' })).toBeInTheDocument();
});

it('gives the body a scroll container and the footer a top border', () => {
  render(
    <Modal open onClose={() => {}} title="t" footer={<button>Book</button>}>
      body
    </Modal>,
  );

  const body = screen.getByText('body');
  expect(body.className).toContain('overflow-y-auto');

  const footer = screen.getByRole('button', { name: 'Book' }).parentElement;
  expect(footer?.className).toContain('border-t');
  expect(footer?.className).toContain('shrink-0');
});
