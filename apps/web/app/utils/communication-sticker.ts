export class CommunicationStickerError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'CommunicationStickerError'
  }
}

export interface CommunicationStickerCrop {
  x: number
  y: number
  width: number
  height: number
}

export interface CommunicationStickerOptions {
  crop?: CommunicationStickerCrop
  maxDimension: number
  maxBytes: number
  fileName?: string
  quality?: number
}

export interface CommunicationStickerDependencies {
  createObjectURL?: (value: Blob) => string
  revokeObjectURL?: (url: string) => void
  loadImage?: (url: string) => Promise<{ width: number, height: number } & CanvasImageSource>
  createCanvas?: () => HTMLCanvasElement
}

function defaultLoadImage(url: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const image = new Image()
    image.onload = () => resolve(image)
    image.onerror = () => reject(new CommunicationStickerError('Não foi possível abrir a imagem da figurinha.'))
    image.src = url
  })
}

function boundedCrop(crop: CommunicationStickerCrop | undefined, width: number, height: number): CommunicationStickerCrop {
  const source = crop ?? { x: 0, y: 0, width, height }
  if (source.width <= 0 || source.height <= 0 || source.x < 0 || source.y < 0 || source.x + source.width > width || source.y + source.height > height) {
    throw new CommunicationStickerError('O recorte da figurinha está fora dos limites da imagem.')
  }
  return source
}

export async function createCommunicationSticker(
  file: File,
  options: CommunicationStickerOptions,
  dependencies: CommunicationStickerDependencies = {}
): Promise<File> {
  if (!file.type.startsWith('image/')) throw new CommunicationStickerError('Selecione uma imagem para criar a figurinha.')
  if (!Number.isFinite(options.maxDimension) || options.maxDimension < 1 || !Number.isFinite(options.maxBytes) || options.maxBytes < 1) {
    throw new CommunicationStickerError('Os limites da figurinha são inválidos.')
  }
  const createObjectURL = dependencies.createObjectURL ?? URL.createObjectURL.bind(URL)
  const revokeObjectURL = dependencies.revokeObjectURL ?? URL.revokeObjectURL.bind(URL)
  const loadImage = dependencies.loadImage ?? defaultLoadImage
  const createCanvas = dependencies.createCanvas ?? (() => document.createElement('canvas'))
  const sourceUrl = createObjectURL(file)
  try {
    const image = await loadImage(sourceUrl)
    const crop = boundedCrop(options.crop, image.width, image.height)
    const ratio = Math.min(1, options.maxDimension / Math.max(crop.width, crop.height))
    const canvas = createCanvas()
    canvas.width = Math.max(1, Math.round(crop.width * ratio))
    canvas.height = Math.max(1, Math.round(crop.height * ratio))
    const context = canvas.getContext('2d')
    if (!context) throw new CommunicationStickerError('Não foi possível preparar a figurinha.')
    context.drawImage(image, crop.x, crop.y, crop.width, crop.height, 0, 0, canvas.width, canvas.height)
    const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/webp', options.quality ?? 0.82))
    if (!blob) throw new CommunicationStickerError('Este navegador não conseguiu gerar uma figurinha WebP.')
    if (blob.size > options.maxBytes) throw new CommunicationStickerError('A figurinha excede o limite de tamanho permitido.')
    return new File([blob], options.fileName ?? 'figurinha.webp', { type: 'image/webp' })
  } finally {
    revokeObjectURL(sourceUrl)
  }
}
