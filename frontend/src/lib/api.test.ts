import { expect, it } from 'vitest';
import { AxiosError, AxiosHeaders } from 'axios';
import { apiFieldErrors } from './api';

function validationError(errors: Record<string, string[]>): AxiosError {
  const err = new AxiosError('Request failed with status code 422');
  err.response = {
    data: { message: 'The given data was invalid.', errors },
    status: 422,
    statusText: 'Unprocessable Content',
    headers: new AxiosHeaders(),
    config: { headers: new AxiosHeaders() },
  };
  return err;
}

it('maps a Laravel validation bag to one message per field', () => {
  const result = apiFieldErrors(
    validationError({
      weight: ['The weight must be greater than 0.', 'Another message.'],
      'dimensions.l': ['The dimensions.l must not be greater than 2000.'],
    }),
  );

  expect(result).toEqual({
    weight: 'The weight must be greater than 0.',
    'dimensions.l': 'The dimensions.l must not be greater than 2000.',
  });
});

it('returns an empty object for a non-validation error', () => {
  expect(apiFieldErrors(new Error('boom'))).toEqual({});
  expect(apiFieldErrors(validationError({}))).toEqual({});
});
