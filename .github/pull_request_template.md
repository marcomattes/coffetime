## Summary

## Testing

<!-- e.g. `php tests/run.php`, PHP lint, `npm run build` if `frontend/` changed, `npm run test:e2e` -->

## Security and privacy impact

<!-- Does this change affect authentication, encrypted names, admin
     capabilities, or any other security- or privacy-sensitive behavior?
     If not, say "None". -->

## Checklist

- [ ] `php tests/run.php` passes
- [ ] PHP lint passes (`find public src tests tools -name '*.php' -print0 | xargs -0 -n1 php -l`)
- [ ] If `frontend/` changed: `npm run build` was run and the updated `public/app.js`/`public/sw.js` are included in this PR
- [ ] No private keys, `config.php`, database files, or other secrets are included
