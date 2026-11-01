import 'dart:math';

import 'package:flutter/material.dart';

import '../api/rewards_models.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Daily spin wheel. Free to play. Prizes are points or coupons, with the odds shown.
class SpinScreen extends StatefulWidget {
  const SpinScreen({super.key});

  @override
  State<SpinScreen> createState() => _SpinScreenState();
}

class _SpinScreenState extends State<SpinScreen> with SingleTickerProviderStateMixin {
  late final AnimationController _wheel = AnimationController(vsync: this, duration: const Duration(seconds: 3));
  List<Map<String, dynamic>> _prizes = [];
  SpinAllowance? _spins;
  double _turns = 0;
  bool _busy = false;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _wheel.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final json = await SessionScope.read(context).api.get('/spin') as Map<String, dynamic>;
      setState(() {
        _prizes = (json['prizes'] as List).whereType<Map<String, dynamic>>().toList();
        _spins = SpinAllowance.fromJson(json['spins'] as Map<String, dynamic>);
        _error = null;
      });
    } on Object catch (e) {
      setState(() => _error = e);
    }
  }

  Future<void> _spin() async {
    setState(() => _busy = true);
    try {
      final json = await SessionScope.read(context).api.post('/spin') as Map<String, dynamic>;
      final prizeId = (json['prize'] as Map<String, dynamic>)['id'];
      final index = max(0, _prizes.indexWhere((p) => p['id'] == prizeId));

      // Spin a few times and stop with the won slice at the top.
      final slice = 1 / _prizes.length;
      final target = _turns.floor() + 4 + (1 - (index + 0.5) * slice);
      final animation = Tween<double>(begin: _turns, end: target).animate(CurvedAnimation(parent: _wheel, curve: Curves.easeOutCubic));
      animation.addListener(() => setState(() => _turns = animation.value));
      _wheel.reset();
      await _wheel.forward();

      if (!mounted) return;
      setState(() => _spins = SpinAllowance.fromJson(json['spins'] as Map<String, dynamic>));
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('🎉 You won!'),
          content: Text((json['prize'] as Map<String, dynamic>)['label'] as String, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
          actions: [FilledButton(onPressed: () => Navigator.pop(context), child: const Text('Great'))],
        ),
      );
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final spins = _spins;

    return Scaffold(
      appBar: AppBar(title: const Text('Spin the wheel')),
      body: _error != null
          ? ErrorRetry(message: _error.toString(), onRetry: _load)
          : spins == null
              ? const Center(child: CircularProgressIndicator())
              : ListView(padding: const EdgeInsets.all(16), children: [
                  Center(
                    child: Stack(alignment: Alignment.topCenter, children: [
                      Transform.rotate(
                        angle: _turns * 2 * pi,
                        child: CustomPaint(size: const Size(280, 280), painter: _WheelPainter(_prizes.map((p) => p['label'] as String).toList())),
                      ),
                      const Icon(Icons.arrow_drop_down, size: 56, color: WiBuyColors.orange),
                    ]),
                  ),
                  const SizedBox(height: 16),
                  Text('${spins.left} spin${spins.left == 1 ? '' : 's'} left today', textAlign: TextAlign.center, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 10),
                  FilledButton(onPressed: _busy || spins.left < 1 ? null : _spin, child: Text(_busy ? 'Spinning…' : 'Spin for free')),
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    onPressed: () => shareOnWhatsApp(context, 'app'),
                    icon: const Icon(Icons.share),
                    label: const Text('Share for another spin'),
                  ),
                  const Text('A spin is added when a friend opens your link (up to the daily limit).', textAlign: TextAlign.center, style: TextStyle(color: WiBuyColors.muted)),
                  const SizedBox(height: 18),
                  const Text('Prizes and chances', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                  for (final prize in _prizes)
                    ListTile(
                      dense: true,
                      contentPadding: EdgeInsets.zero,
                      title: Text(prize['label'] as String),
                      trailing: Text('${prize['odds_percent']}%', style: const TextStyle(fontWeight: FontWeight.w700)),
                    ),
                  const Text('Prizes are WiBuy Points or coupons. Spinning is always free.', style: TextStyle(color: WiBuyColors.muted, fontSize: 12)),
                ]),
    );
  }
}

class _WheelPainter extends CustomPainter {
  _WheelPainter(this.labels);

  final List<String> labels;

  static const _colors = [Color(0xFF15803D), Color(0xFFEA580C), Color(0xFF16A34A), Color(0xFFF59E0B), Color(0xFF166534), Color(0xFFF97316)];

  @override
  void paint(Canvas canvas, Size size) {
    if (labels.isEmpty) return;
    final center = size.center(Offset.zero);
    final radius = size.width / 2;
    final sweep = 2 * pi / labels.length;

    for (var i = 0; i < labels.length; i++) {
      // Slice 0 starts at the top (where the pointer is).
      final start = -pi / 2 + i * sweep;
      canvas.drawArc(Rect.fromCircle(center: center, radius: radius), start, sweep, true, Paint()..color = _colors[i % _colors.length]);

      final painter = TextPainter(
        text: TextSpan(text: labels[i], style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700)),
        textDirection: TextDirection.ltr,
      )..layout(maxWidth: radius * 0.6);
      final angle = start + sweep / 2;
      canvas.save();
      canvas.translate(center.dx + cos(angle) * radius * 0.6, center.dy + sin(angle) * radius * 0.6);
      canvas.rotate(angle + pi / 2);
      painter.paint(canvas, Offset(-painter.width / 2, -painter.height / 2));
      canvas.restore();
    }
    canvas.drawCircle(center, 18, Paint()..color = Colors.white);
  }

  @override
  bool shouldRepaint(covariant _WheelPainter old) => old.labels != labels;
}
