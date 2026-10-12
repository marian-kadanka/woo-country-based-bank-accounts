/**
 * What the plugin is documented to do, written out once so every test asserts against the same
 * rules rather than against each other's implementation.
 *
 * Two rules, and they are independent:
 *
 *   printing  a customer is printed the bank accounts assigned to their billing country; when
 *             none is assigned to it, all accounts are printed rather than none
 *   checkout  with the switch on, direct bank transfer is hidden from a customer whose billing
 *             country is assigned to no bank account at all
 *
 * A bank account with no country is assigned to every country, so it always prints and it also
 * keeps the gateway available whatever the switch says.
 */

/** The account shapes the matrices are built from. `null` means "no country assigned". */
const SHAPES = {
  // Every account is assigned, and two of them share a country so the printing assertions can
  // tell "the accounts for this country" apart from "the first account for this country".
  'all-assigned': { a: ['SK'], b: ['CZ'], c: ['SK'] },
  // One account left unassigned: it matches every country, which negates the switch.
  'one-unassigned': { a: ['SK'], b: ['CZ'], c: null },
};

/** Refs of the accounts assigned to a billing country, ignoring the fallback. */
function matching(shape, country) {
  const map = SHAPES[shape];
  return Object.keys(map).filter((ref) => !map[ref] || (country && map[ref].includes(country)));
}

/**
 * Refs of the accounts that must be printed after an order billed to `country`.
 *
 * @param {string} shape Key of SHAPES.
 * @param {string} country Billing country code.
 */
function expectedPrinted(shape, country) {
  const hits = matching(shape, country);
  return hits.length ? hits : Object.keys(SHAPES[shape]);
}

/**
 * Is direct bank transfer offered on the checkout page?
 *
 * @param {boolean} hide The switch.
 * @param {string} shape Key of SHAPES.
 * @param {string} country Billing country code, '' when the customer has not given one.
 */
function expectedBacsOffered(hide, shape, country) {
  if (!hide) return true;
  if (!country) return true; // the plugin leaves the gateway alone until a country is known
  return matching(shape, country).length > 0;
}

module.exports = { SHAPES, matching, expectedPrinted, expectedBacsOffered };
