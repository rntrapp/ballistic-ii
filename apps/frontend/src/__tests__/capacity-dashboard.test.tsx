import { render, screen, within } from "@testing-library/react";
import { CapacityDashboard } from "@/components/CapacityDashboard";
import type { VelocityForecast } from "@/types";

function fc(overrides: Partial<VelocityForecast> = {}): VelocityForecast {
  return {
    velocity: 10,
    std_dev: 2,
    capacity_upper: 12,
    upcoming_load: 5,
    burnout_risk: false,
    probability_of_success: 0.95,
    weekly_history: [8, 10, 12, 10],
    sample_weeks: 4,
    ...overrides,
  };
}

describe("CapacityDashboard", () => {
  test("renders nothing when forecast is null", () => {
    const { container } = render(<CapacityDashboard forecast={null} />);
    expect(container).toBeEmptyDOMElement();
  });

  test("renders nothing on cold start (sample_weeks === 0)", () => {
    const { container } = render(
      <CapacityDashboard forecast={fc({ sample_weeks: 0 })} />,
    );
    expect(container).toBeEmptyDOMElement();
  });

  test("announces On Track when burnout_risk is false", () => {
    render(<CapacityDashboard forecast={fc({ burnout_risk: false })} />);
    const status = screen.getByRole("status");
    expect(within(status).getByText("On Track")).toBeInTheDocument();
    expect(within(status).queryByText("Burnout Risk")).not.toBeInTheDocument();
  });

  test("announces Burnout Risk when burnout_risk is true", () => {
    render(<CapacityDashboard forecast={fc({ burnout_risk: true })} />);
    const status = screen.getByRole("status");
    expect(within(status).getByText("Burnout Risk")).toBeInTheDocument();
    expect(within(status).queryByText("On Track")).not.toBeInTheDocument();
  });

  test("surfaces load, capacity and probability", () => {
    render(
      <CapacityDashboard
        forecast={fc({
          upcoming_load: 8,
          capacity_upper: 12,
          probability_of_success: 0.159,
        })}
      />,
    );
    const status = screen.getByRole("status");
    // "8 / 12.0 pts this week · 16% likely"
    expect(status).toHaveTextContent("8");
    expect(status).toHaveTextContent("12.0 pts this week");
    expect(status).toHaveTextContent("16% likely");
  });

  test("rounds probability to nearest percent", () => {
    render(
      <CapacityDashboard forecast={fc({ probability_of_success: 0.997 })} />,
    );
    expect(screen.getByRole("status")).toHaveTextContent("100% likely");
  });

  test("gauge clamps at 100% when load blows past capacity", () => {
    const { container } = render(
      <CapacityDashboard
        forecast={fc({ upcoming_load: 50, capacity_upper: 12 })}
      />,
    );
    // Only the gauge fill uses an inline width style.
    const bar = container.querySelector<HTMLElement>('[style*="width"]');
    expect(bar).not.toBeNull();
    expect(bar!.style.width).toBe("100%");
  });

  test("gauge scales proportionally below capacity", () => {
    const { container } = render(
      <CapacityDashboard
        forecast={fc({ upcoming_load: 6, capacity_upper: 12 })}
      />,
    );
    const bar = container.querySelector<HTMLElement>('[style*="width"]');
    expect(bar!.style.width).toBe("50%");
  });

  test("sparkline renders one bar per history week", () => {
    render(
      <CapacityDashboard
        forecast={fc({ weekly_history: [1, 2, 3, 4, 5, 6, 7] })}
      />,
    );
    const svg = screen.getByLabelText(/weekly velocity trend, 7 weeks/i);
    expect(svg.querySelectorAll("rect")).toHaveLength(7);
  });

  // Reactivity contract: the component is a pure function of its prop.
  // page.tsx swaps the forecast object synchronously on effort change —
  // this proves the label actually flips when that happens.
  test("rerender with flipped burnout_risk switches the label", () => {
    const { rerender } = render(
      <CapacityDashboard forecast={fc({ burnout_risk: false })} />,
    );
    expect(screen.getByText("On Track")).toBeInTheDocument();

    rerender(<CapacityDashboard forecast={fc({ burnout_risk: true })} />);
    expect(screen.getByText("Burnout Risk")).toBeInTheDocument();
    expect(screen.queryByText("On Track")).not.toBeInTheDocument();
  });

  test("shows velocity footnote", () => {
    render(<CapacityDashboard forecast={fc({ velocity: 7.3 })} />);
    expect(screen.getByRole("status")).toHaveTextContent("velocity 7.3 pts/wk");
  });
});
