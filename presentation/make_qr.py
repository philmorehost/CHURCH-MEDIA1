"""Generate themed QR codes for the church website and the mobile app."""
from pathlib import Path

import qrcode
from qrcode.image.styledpil import StyledPilImage
from qrcode.image.styles.colormasks import SolidFillColorMask

ASSETS = Path(__file__).parent / "assets"
ASSETS.mkdir(parents=True, exist_ok=True)

TARGETS = {
    "qr_website": "https://rccglp63yaya.org.ng",
    "qr_app": "https://play.google.com/store/apps/details?id=com.churchmedia.app",
}


def build(name: str, url: str) -> None:
    qr = qrcode.QRCode(
        version=None,
        error_correction=qrcode.constants.ERROR_CORRECT_H,
        box_size=16,
        border=2,
    )
    qr.add_data(url)
    qr.make(fit=True)
    img = qr.make_image(
        image_factory=StyledPilImage,
        color_mask=SolidFillColorMask(
            back_color=(14, 13, 24),
            front_color=(232, 185, 95),
        ),
    ).convert("RGB")
    out = ASSETS / f"{name}.png"
    img.save(out)
    print(f"wrote {out.name}: {img.size[0]}x{img.size[1]}")


if __name__ == "__main__":
    for key, value in TARGETS.items():
        build(key, value)
