import { useState } from 'react';
import { Card, Select } from '../ui';

/**
 * Filament colour choice for MODEL_3D products. Logo placement happens
 * on the shared DesignerCanvas (the item is FDM-printed, then UV-decorated
 * on its flat face - a real production step, so the placement mockup the
 * customer approves is producible).
 */

export interface Model3dCustomization {
  filament_color: string;
}

// Keep aligned with FilamentSeeder / actual spool inventory - offering a
// colour with no spool row goes QTY_SHORT at procurement.
const FILAMENT_COLORS = ['Black', 'White', 'Grey'];

// White by default: the hint recommends light colours for UV contrast, so the
// default must not steer first-time buyers into the worst choice (audit G6).
export const DEFAULT_FILAMENT_COLOR = 'White';

interface Props {
  onChange: (customization: Model3dCustomization) => void;
}

export default function Model3dPersonalizer({ onChange }: Props) {
  const [color, setColor] = useState(DEFAULT_FILAMENT_COLOR);

  return (
    <Card padding="md" className="flex flex-col gap-3 sm:max-w-sm">
      <Select
        label="Filament colour"
        value={color}
        onChange={(e) => {
          setColor(e.target.value);
          onChange({ filament_color: e.target.value });
        }}
        hint="Light colours give the best contrast for UV-printed logos."
      >
        {FILAMENT_COLORS.map((c) => (
          <option key={c} value={c}>
            {c}
          </option>
        ))}
      </Select>
      <p className="text-sm text-fg-muted">
        Your item is 3D-printed in this colour - the design preview shows the
        model in your chosen colour. Place your logo on the decoration face
        shown; it is UV-printed exactly where you place it, and the formal
        proof you approve confirms the final result.
      </p>
    </Card>
  );
}
