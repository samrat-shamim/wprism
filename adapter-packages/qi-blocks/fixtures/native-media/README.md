# Native crop selections

`blocks.html` contains three byte-preserved block fragments from the actual
WordPress editor Save on WordPress 7.1 / Qi Blocks 1.5.2, using engine source
`47cdce79a539184f28a832c78ead77fa367ce30c`. The saved page reopened with all
51 blocks valid. The fragments retain the four selected native crop controls:

| native consumer | requested dimensions |
| --- | --- |
| Single Image | 347 × 219 |
| Author Info image | 251 × 157 |
| Parallax Images main image | 317 × 193 |
| Parallax Images first repeated item | 293 × 181 |

Each selection used Image Size → Custom → Width / Height → Apply Changes.
Qi's resize requests and WordPress page/style Save requests returned HTTP 200.
The complete saved body SHA-256 was
`f1031202f687ac1a4c6c0fba4a78afb46c145ddfe12e9f84bbf8dfd2145f41d6`;
the extracted fragments hash to
`aa4d08e52ab6b1e751cbd011f749f4af408c062445de104bcc3c7365ca88f6b8`.
The source home is `http://localhost:9176`, attachment ID 1, page ID 4.
`original.png` is that attachment's native 1200 × 800 uniform RGB(43,108,132)
image, SHA-256
`741e83c72739ccce22a24ec18c5a32be8bde40bd669324b0b5017a6e36c0131e`.
It supports file-selection and output-size checks, not asymmetric crop-pixel
qualification.

The locked plugin archive is `qi-blocks.1.5.2.zip`, SHA-256
`6168357231ad0d39e41bcf71b1a0d4ad0fa08a5cc4263cc708dfe198b1f1f887`.
Its `assets/dist/{single-image,author-info,parallax-images}.js` image controls
omit `allowScreens`; the shared control defaults it to false. The save
renderers consume `image`, `mainImage` and `items[].itemImage.image`.
Tablet/Mobile attributes come from the shared registration helper, but these
image controls neither author nor render them. Their stored reference values
still transport; they do not authorize generated files.

The previous declaration omitted the repeated-item recipe. On a target with
attachment ID 9 / page ID 12, Capture, Apply and complete compiled recapture
all passed while the saved 293 × 181 image returned HTTP 404 and had browser
natural dimensions 0 × 0. The Single Image crop returned HTTP 200 and rendered
at 347 × 219. The offline regression reproduces the omitted file work and the
previously unchecked inconsistent nested dimensions before the declaration
fix. Native validation of the corrected declaration is recorded separately;
this retained source fixture alone does not establish that result.

Selecting Custom with empty dimensions also saved successfully while keeping
the original URL. The shared engine already treats that state as no crop work.
Both source and target editors emitted the same WordPress iframe stylesheet
warning; it is not a clean-console qualification. The capsule remains
experimental, with lifecycle, host, version and combination work outstanding.
