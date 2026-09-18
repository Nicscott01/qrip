# QRip

QRip creates first-party, editable QR redirects. Every QR image encodes `https://your-site.example/go/your-slug`, never its current destination. Active links redirect with HTTP 302; paused links return HTTP 410. PHP 8.4+ and WordPress 6.4+ are required.

## Print guidance

Create one QR code per distinct printed placement or campaign. Use separate codes when attribution by placement matters. Test every code on a real phone before sending artwork to print. Use SVG whenever possible and do not crop the white border. Keep a physical code at least 0.8 inches / 20 mm wide for ordinary print use; use larger codes for signs or distance scanning.

QR records are retained on deactivation and uninstall so printed links do not silently disappear.

## Permanently retiring a QR code

To remove a test or unwanted code, open its edit screen and expand the collapsed **Danger zone**. The action is separate from updating the record and requires typing `DELETE` exactly. Deletion permanently retires the managed `/go/{slug}` URL, which returns a non-cached HTTP 410 response and can never be reused. Printed or shared copies cannot be recalled; pause a code instead when it may be needed again.

Retirement removes the destination, Media Library reference, notes, UTM values, scan data, and other editable record metadata. QRip retains only the slug, deleted state, deletion time, and deleting user so the old URL cannot be silently reassigned. The underlying Media Library attachment is never deleted.
