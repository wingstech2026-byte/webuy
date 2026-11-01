import 'package:flutter/material.dart';

import '../api/models.dart';
import '../session.dart';
import '../widgets/common.dart';

/// Search and category pages, with the website's sort options and paging.
class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key, this.category, this.title});

  final String? category;
  final String? title;

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final _query = TextEditingController();
  final _products = <Product>[];
  String _sort = '';
  int _page = 0;
  bool _hasMore = true;
  bool _loading = false;
  Object? _error;

  // Same keys as SearchService::SORTS in Laravel; '' is the default best match.
  static const _sorts = {
    '': 'Best match',
    'newest': 'Newest',
    'price_asc': 'Price: low to high',
    'price_desc': 'Price: high to low',
    'rating': 'Best rated',
  };

  @override
  void initState() {
    super.initState();
    _search();
  }

  @override
  void dispose() {
    _query.dispose();
    super.dispose();
  }

  Future<void> _search({bool more = false}) async {
    if (_loading) return;
    setState(() {
      _loading = true;
      _error = null;
      if (!more) {
        _products.clear();
        _page = 0;
      }
    });
    try {
      final json = await SessionScope.read(context).api.get('/products', query: {
        'q': _query.text.trim(),
        'category': widget.category,
        'sort': _sort,
        'page': '${_page + 1}',
      }) as Map<String, dynamic>;
      final page = Paged.fromJson(json, Product.fromJson);
      setState(() {
        _products.addAll(page.items);
        _page = page.currentPage;
        _hasMore = page.hasMore;
      });
    } on Object catch (e) {
      setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title ?? 'Search'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(64),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 10),
            child: TextField(
              controller: _query,
              textInputAction: TextInputAction.search,
              onSubmitted: (_) => _search(),
              decoration: InputDecoration(
                hintText: 'What are you looking for?',
                prefixIcon: const Icon(Icons.search),
                contentPadding: const EdgeInsets.symmetric(vertical: 10),
                suffixIcon: IconButton(icon: const Icon(Icons.arrow_forward), onPressed: _search),
              ),
            ),
          ),
        ),
      ),
      body: NotificationListener<ScrollNotification>(
        onNotification: (n) {
          if (n.metrics.pixels > n.metrics.maxScrollExtent - 400 && _hasMore && !_loading && _error == null) {
            _search(more: true);
          }
          return false;
        },
        child: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
          SizedBox(
            height: 52,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              children: [
                for (final entry in _sorts.entries)
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      label: Text(entry.value),
                      selected: _sort == entry.key,
                      onSelected: (_) {
                        setState(() => _sort = entry.key);
                        _search();
                      },
                    ),
                  ),
              ],
            ),
          ),
          if (_error != null && _products.isEmpty)
            SizedBox(height: 360, child: ErrorRetry(message: _error.toString(), onRetry: _search))
          else if (!_loading && _products.isEmpty)
            const SizedBox(height: 360, child: EmptyState(icon: Icons.search_off, title: 'Nothing found. Try another word.'))
          else
            ProductGrid(_products),
          if (_loading) const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator())),
        ]),
      ),
    );
  }
}
