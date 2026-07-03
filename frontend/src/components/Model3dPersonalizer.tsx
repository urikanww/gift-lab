import { useState } from 'react';
import { Card, Select } from '../ui';

/**
 * Filament colour choice for MODEL_3D products. Logo placement happens
 * on the shared DesignerCanvas (the item is FDM-printed, then UV-decorated
 * on its flat face — a real production step, so the placement mockup the
 * customer approves is producible).
 */

export interface Model3dCustomization {
  filament_color: string;
}

// Keep aligned with FilamentSeeder / actual spool inventory — offering a
// colour with no spool row goes QTY_SHORT at procurement.
const FILAMENT_COLORS = ['Black', 'White', 'Grey'];

interface Props {
  onChange: (customization: Model3dCustomization) => void;
}

export default function Model3dPersonalizer({ onChange }: Props) {
  const [color, setColor] = useState(FILAMENT_COLORS[0]);

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
        Your item is 3D-printed in this colour, then your design is UV-printed
        onto its flat face. The formal proof you approve shows the exact result.
      </p>
    </Card>
  );
}
