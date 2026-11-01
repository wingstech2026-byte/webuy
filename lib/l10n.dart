import 'package:flutter/widgets.dart';

import 'session.dart';

/// The app's languages. English is the base: a phrase with no Krio yet shows in English.
const appLanguages = {'en': 'English', 'kri': 'Krio'};

/// Krio for the main screens, keyed by the English text. Keep the website's lang/kri.json in step.
/// To be checked by a Krio speaker before launch.
const _krio = {
  // Bottom bar
  'Home': 'Os',
  'Rewards': 'Prayz',
  'Cart': 'Baskit',
  'Orders': 'Oda dem',
  'Account': 'Mi akawnt',
  // Home
  'Search rice, phones, cloth…': 'Fen res, fon, klos…',
  "Today's lightning deals": 'Kwik-kwik gud pris tide',
  'Low prices for a few hours only': 'Lo pris fo smol awa nomo',
  'Buy together, pay less': 'Bai togeda, pe smol',
  'Group prices when enough people buy. Money back if the group does not fill.': 'Grup pris we plenti pipul bai. Yu go get yu moni bak if di grup no ful.',
  'Shops near me': 'Shop dem we nia mi',
  'See the closest shops on the map': 'Si di shop dem we nia yu na map',
  '🔥 Deals': '🔥 Gud pris',
  'Top shops': 'Bes shop dem',
  'Popular': 'Wetin pipul lek',
  'New': 'Nyu tin dem',
  '✨ For you': '✨ Fo yu',
  // Product
  'Add to cart': 'Put am na baskit',
  'Buy now': 'Bai am naw',
  // Account
  'Log in': 'Log in',
  'Log in with your phone number to buy, track orders and save products.': 'Log in wit yu fon nomba fo bai, fala yu oda, en sev tin dem.',
  'Saved products': 'Tin dem we yu sev',
  'My shop': 'Mi shop',
  'Chat': 'Tok',
  'Notifications': 'Nyuz fo yu',
  'Save data': 'Sev data',
  'Smaller pictures, for slow or costly data': 'Smol foto dem, fo slo o kost data',
  'Language': 'Langwej',
};

/// [english] in the language chosen on this phone (Account → Language).
String tr(BuildContext context, String english) => SessionScope.of(context).language == 'kri' ? (_krio[english] ?? english) : english;
