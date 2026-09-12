import 'package:flutter/material.dart';
import 'screens/bible_screen.dart';
import 'screens/feed_screen.dart';
import 'screens/home_screen.dart';
import 'screens/more_screen.dart';
import 'screens/sermons_screen.dart';
import 'services/app_nav.dart';
import 'theme/app_theme.dart';

class ChurchMediaApp extends StatelessWidget {
  const ChurchMediaApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Church Media',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.dark,
      darkTheme: AppTheme.dark,
      themeMode: ThemeMode.dark,
      navigatorKey: AppNav.navigatorKey,
      home: const RootShell(),
    );
  }
}

/// Bottom-nav shell — Home / Feed(Reels) / Bible / Sermons / More.
/// Events moved into the More menu; the Bible is always one tap away.
/// No login anywhere: every screen here is publicly viewable.
class RootShell extends StatefulWidget {
  const RootShell({super.key});
  @override
  State<RootShell> createState() => _RootShellState();
}

class _RootShellState extends State<RootShell> {
  int _index = 0;
  final _feedKey = GlobalKey<FeedScreenState>();

  @override
  void initState() {
    super.initState();
    // Let push taps (and other global calls) switch tabs.
    AppNav.onSwitchTab = _goTo;
  }

  void _goTo(int index) {
    if (index == _index) {
      if (index == 1) _feedKey.currentState?.refresh();
      return;
    }
    setState(() => _index = index);
  }

  @override
  Widget build(BuildContext context) {
    final screens = [
      HomeScreen(onNavigate: _goTo),
      FeedScreen(key: _feedKey),
      const BibleScreen(),
      const SermonsScreen(),
      const MoreScreen(),
    ];

    return Scaffold(
      body: IndexedStack(index: _index, children: screens),
      bottomNavigationBar: BottomNavigationBar(
        currentIndex: _index,
        onTap: _goTo,
        items: const [
          BottomNavigationBarItem(icon: Icon(Icons.home_outlined), activeIcon: Icon(Icons.home), label: 'Home'),
          BottomNavigationBarItem(icon: Icon(Icons.play_circle_outline), activeIcon: Icon(Icons.play_circle), label: 'Feed'),
          BottomNavigationBarItem(icon: Icon(Icons.auto_stories_outlined), activeIcon: Icon(Icons.auto_stories), label: 'Bible'),
          BottomNavigationBarItem(icon: Icon(Icons.menu_book_outlined), activeIcon: Icon(Icons.menu_book), label: 'Sermons'),
          BottomNavigationBarItem(icon: Icon(Icons.more_horiz), label: 'More'),
        ],
      ),
    );
  }
}
