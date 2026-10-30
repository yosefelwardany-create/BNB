import {
  CircleHelp,
  House,
  Image,
  Images,
  LayoutPanelLeft,
  MapPin,
  Megaphone,
  Phone,
  SeparatorHorizontal,
  Sparkles,
  Square,
  Star,
  Type,
  type LucideIcon,
} from 'lucide-react'

/** The icon the server names for each block, as drawn in the editor. */
const ICONS: Record<string, LucideIcon> = {
  image: Image,
  house: House,
  type: Type,
  'layout-panel-left': LayoutPanelLeft,
  images: Images,
  star: Star,
  sparkles: Sparkles,
  'map-pin': MapPin,
  'circle-help': CircleHelp,
  megaphone: Megaphone,
  phone: Phone,
  'separator-horizontal': SeparatorHorizontal,
}

export function blockIcon(name: string): LucideIcon {
  return ICONS[name] ?? Square
}
