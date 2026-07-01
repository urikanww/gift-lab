import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Badge } from './Badge';
import { Button } from './Button';
import { Input } from './Input';
import { Modal } from './Modal';
import { EmptyState } from './EmptyState';
import { ToastProvider, useToast } from './Toast';

describe('Button', () => {
  it('renders as a button and fires onClick', async () => {
    const onClick = vi.fn();
    render(<Button onClick={onClick}>Save</Button>);
    await userEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect(onClick).toHaveBeenCalledOnce();
  });

  it('is disabled and busy while loading', () => {
    render(<Button loading>Save</Button>);
    const btn = screen.getByRole('button', { name: 'Save' });
    expect(btn).toBeDisabled();
    expect(btn).toHaveAttribute('aria-busy', 'true');
  });
});

describe('Badge', () => {
  it('renders its content', () => {
    render(<Badge tone="success">Ready</Badge>);
    expect(screen.getByText('Ready')).toBeInTheDocument();
  });
});

describe('Input', () => {
  it('associates label, hint, and error for a11y', () => {
    render(<Input label="Email" error="Required" />);
    const input = screen.getByLabelText('Email');
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByRole('alert')).toHaveTextContent('Required');
  });
});

describe('EmptyState', () => {
  it('renders title and description', () => {
    render(<EmptyState title="Nothing here" description="Add your first item." />);
    expect(screen.getByRole('heading', { name: 'Nothing here' })).toBeInTheDocument();
    expect(screen.getByText('Add your first item.')).toBeInTheDocument();
  });
});

describe('Modal', () => {
  it('does not render when closed', () => {
    render(
      <Modal open={false} onClose={() => {}} title="Confirm">
        Body
      </Modal>,
    );
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('renders an accessible dialog when open', () => {
    render(
      <Modal open onClose={() => {}} title="Confirm" description="Are you sure?">
        Body
      </Modal>,
    );
    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(dialog).toHaveAccessibleName('Confirm');
  });

  it('closes on Escape and on close button', async () => {
    const onClose = vi.fn();
    render(
      <Modal open onClose={onClose} title="Confirm">
        Body
      </Modal>,
    );
    await userEvent.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalled();

    await userEvent.click(screen.getByRole('button', { name: /close dialog/i }));
    expect(onClose).toHaveBeenCalledTimes(2);
  });
});

describe('Toast', () => {
  function Harness() {
    const { toast } = useToast();
    return (
      <button type="button" onClick={() => toast({ title: 'Saved', tone: 'success', duration: 0 })}>
        fire
      </button>
    );
  }

  it('shows a toast when triggered', async () => {
    render(
      <ToastProvider>
        <Harness />
      </ToastProvider>,
    );
    await userEvent.click(screen.getByRole('button', { name: 'fire' }));
    await waitFor(() => expect(screen.getByText('Saved')).toBeInTheDocument());
  });

  it('dismisses a toast', async () => {
    render(
      <ToastProvider>
        <Harness />
      </ToastProvider>,
    );
    await userEvent.click(screen.getByRole('button', { name: 'fire' }));
    await waitFor(() => expect(screen.getByText('Saved')).toBeInTheDocument());
    await userEvent.click(screen.getByRole('button', { name: /dismiss notification/i }));
    await waitFor(() => expect(screen.queryByText('Saved')).not.toBeInTheDocument());
  });
});
