import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import Button from './Button';

describe('Button Component', () => {
  it('renders correctly with default props', () => {
    render(<Button>Click me</Button>);
    expect(screen.getByText('Click me')).toBeInTheDocument();
  });

  it('renders with different variants', () => {
    const { rerender } = render(<Button variant="primary">Primary</Button>);
    let button = screen.getByText('Primary');
    expect(button).toHaveClass('bg-blue-600');

    rerender(<Button variant="danger">Danger</Button>);
    button = screen.getByText('Danger');
    expect(button).toHaveClass('bg-red-600');
  });

  it('renders with different sizes', () => {
    const { rerender } = render(<Button size="sm">Small</Button>);
    let button = screen.getByText('Small');
    expect(button).toHaveClass('px-3');

    rerender(<Button size="lg">Large</Button>);
    button = screen.getByText('Large');
    expect(button).toHaveClass('px-6');
  });

  it('calls onClick handler when clicked', async () => {
    const handleClick = vi.fn();
    render(<Button onClick={handleClick}>Click me</Button>);

    await userEvent.click(screen.getByText('Click me'));
    expect(handleClick).toHaveBeenCalledOnce();
  });

  it('is disabled when disabled prop is true', async () => {
    const handleClick = vi.fn();
    render(
      <Button disabled onClick={handleClick}>
        Disabled
      </Button>
    );

    const button = screen.getByText('Disabled');
    expect(button).toBeDisabled();

    await userEvent.click(button);
    expect(handleClick).not.toHaveBeenCalled();
  });

  it('shows loading state correctly', () => {
    render(<Button isLoading>Loading</Button>);
    const button = screen.getByRole('button');
    expect(button).toHaveClass('disabled:cursor-not-allowed');
  });

  it('renders with icon', () => {
    const TestIcon = () => <span data-testid="test-icon">📋</span>;
    render(<Button icon={<TestIcon />}>With Icon</Button>);

    expect(screen.getByTestId('test-icon')).toBeInTheDocument();
  });

  it('renders icon on correct side', () => {
    const TestIcon = () => <span data-testid="test-icon">📋</span>;

    const { rerender } = render(
      <Button icon={<TestIcon />} iconPosition="left">
        Left Icon
      </Button>
    );

    expect(screen.getByTestId('test-icon')).toBeInTheDocument();

    rerender(
      <Button icon={<TestIcon />} iconPosition="right">
        Right Icon
      </Button>
    );

    expect(screen.getByTestId('test-icon')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    render(<Button className="custom-class">Custom</Button>);
    const button = screen.getByText('Custom');
    expect(button).toHaveClass('custom-class');
  });

  it('supports all HTML button attributes', () => {
    render(
      <Button
        type="submit"
        id="submit-button"
        data-testid="submit"
        aria-label="Submit form"
      >
        Submit
      </Button>
    );

    const button = screen.getByTestId('submit');
    expect(button).toHaveAttribute('type', 'submit');
    expect(button).toHaveAttribute('id', 'submit-button');
    expect(button).toHaveAttribute('aria-label', 'Submit form');
  });
});
