import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ThemeProvider } from '../../ui';
import NeedByField from './NeedByField';
import type { LeadTimeEstimate } from '../../types';

const lead: LeadTimeEstimate = {
  earliest: '2026-08-01',
  latest: '2026-08-10',
  rush_available: true,
  rush_earliest: '2026-07-28',
  rush_fee: 15,
};

function renderField(props: Partial<React.ComponentProps<typeof NeedByField>> = {}) {
  return render(
    <ThemeProvider>
      <NeedByField lead={lead} value="" onChange={() => {}} {...props} />
    </ThemeProvider>,
  );
}

it('shows a bare date picker when there is no estimate', () => {
  render(
    <ThemeProvider>
      <NeedByField lead={null} value="" onChange={() => {}} />
    </ThemeProvider>,
  );
  expect(screen.getByLabelText(/need it by/i)).toBeInTheDocument();
  expect(screen.queryByText(/estimated delivery/i)).not.toBeInTheDocument();
});

it('shows the arrival window and no badge until a date is chosen', () => {
  renderField({ value: '' });
  expect(screen.getByText(/estimated delivery/i)).toBeInTheDocument();
  expect(screen.queryByText(/on track|tight|at risk/i)).not.toBeInTheDocument();
});

it('marks a deadline on or after the latest arrival as on track', () => {
  renderField({ value: '2026-08-12' }); // >= latest 2026-08-10
  expect(screen.getByText(/on track/i)).toBeInTheDocument();
  expect(screen.queryByText(/tight|at risk|rush/i)).not.toBeInTheDocument();
});

it('marks a deadline inside the window as tight without pushing rush', () => {
  renderField({ value: '2026-08-05' }); // earliest <= date < latest
  expect(screen.getByText(/^tight$/i)).toBeInTheDocument();
  expect(screen.getByText(/cutting it close/i)).toBeInTheDocument();
  expect(screen.queryByText(/rush can arrive/i)).not.toBeInTheDocument();
});

it('marks a deadline before the earliest arrival as at risk with the rush option', () => {
  renderField({ value: '2026-07-30' }); // < earliest 2026-08-01
  expect(screen.getByText(/at risk/i)).toBeInTheDocument();
  expect(screen.getByText(/before our earliest/i)).toBeInTheDocument();
  expect(screen.getByText(/rush can arrive/i)).toBeInTheDocument();
});
