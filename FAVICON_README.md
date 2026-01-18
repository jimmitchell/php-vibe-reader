# Favicon Setup

The project includes a favicon in SVG format (`favicon.svg`) that represents an RSS reader with:
- Radio wave symbol (RSS feed icon)
- Document/reader symbol (representing articles)

## Browser Support

Modern browsers (Chrome, Firefox, Safari, Edge) support SVG favicons natively. The SVG favicon is the primary favicon.

## Creating favicon.ico (Optional)

If you need a traditional ICO file for older browsers or specific use cases, you can convert the SVG to ICO:

### Using Online Tools
1. Visit https://convertio.co/svg-ico/ or https://cloudconvert.com/svg-to-ico
2. Upload `favicon.svg`
3. Download the converted `favicon.ico`
4. Place it in the project root

### Using ImageMagick (if installed)
```bash
convert favicon.svg -resize 16x16 -background transparent favicon.ico
```

### Using Inkscape
```bash
inkscape favicon.svg --export-filename=favicon.ico --export-width=16 --export-height=16
```

The current setup will work with modern browsers using the SVG favicon. The ICO file is optional but recommended for maximum compatibility.
