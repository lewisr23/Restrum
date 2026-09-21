import { createContext, useContext, useState, ReactNode } from 'react';

interface AuthUser {
  id: number;
  username: string;
  email: string;
  token: string;

  // Undefined rather than null for anyone whose stored record predates
  // email verification existing. The banner treats the two differently:
  // null means "we asked and they have not confirmed", undefined means
  // "we have not looked yet", and only the first is worth nagging about.
  email_verified_at?: string | null;
}

interface AuthContextType {
  user: AuthUser | null;
  login: (user: AuthUser) => void;
  logout: () => void;

  /** Merge fields into the stored user without a re-login. */
  updateUser: (patch: Partial<AuthUser>) => void;
}

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(() => {
    const stored = localStorage.getItem('tt_user');
    return stored ? JSON.parse(stored) : null;
  });

  const login = (user: AuthUser) => {
    localStorage.setItem('tt_user', JSON.stringify(user));
    setUser(user);
  };

  const logout = () => {
    localStorage.removeItem('tt_user');
    setUser(null);
  };

  const updateUser = (patch: Partial<AuthUser>) => {
    setUser((current) => {
      if (!current) return current;

      const next = { ...current, ...patch };
      localStorage.setItem('tt_user', JSON.stringify(next));
      return next;
    });
  };

  return (
    <AuthContext.Provider value={{ user, login, logout, updateUser }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
