import { BrowserRouter, Routes, Route } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import Navbar from './components/Navbar';
import Browse from './components/Browse';
import ListingDetail from './components/ListingDetail';
import CreateListing from './components/CreateListing';
import EditListing from './components/EditListing';
import Checkout from './components/Checkout';
import Orders from './components/Orders';
import OrderDetail from './components/OrderDetail';
import SellerPayments from './components/SellerPayments';
import About from './components/About';
import Faq from './components/Faq';
import Terms from './components/Terms';
import Privacy from './components/Privacy';
import Login from './components/Login';
import AdminReports from './components/AdminReports';
import ForgotPassword from './components/ForgotPassword';
import ResetPassword from './components/ResetPassword';
import EmailVerified from './components/EmailVerified';
import VerifyEmailBanner from './components/VerifyEmailBanner';
import Register from './components/Register';
import MessagesPage from './components/Messages';
import SellerProfile from './components/SellerProfile';
import SavedListings from './components/SavedListings';
import Footer from './components/Footer';
import GearAdviser from './components/GearAdviser';

// Routing and nothing else.
//
// The homepage used to live here, along with the hero and a hardcoded list of
// five categories. It moved to components/Browse once browsing grew a
// category tree and a filter panel: a router file that also contains a
// faceted search is a file nobody can find anything in.
function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <div className="app-shell">
          <Navbar />

          {/* Above the routes rather than inside one: an unconfirmed
              address matters on every page, and the banner renders
              nothing for everyone else. */}
          <VerifyEmailBanner />

          <div className="app-shell__main">
            <Routes>
              <Route path="/" element={<Browse />} />
              <Route path="/listing/:id" element={<ListingDetail />} />
              <Route path="/listing/:id/edit" element={<EditListing />} />
              <Route path="/checkout/:id" element={<Checkout />} />
              <Route path="/orders" element={<Orders />} />
              <Route path="/orders/:id" element={<OrderDetail />} />
              <Route path="/sell/payments" element={<SellerPayments />} />
              <Route path="/seller/:id" element={<SellerProfile />} />
              <Route path="/saved" element={<SavedListings />} />
              <Route path="/create" element={<CreateListing />} />
              <Route path="/messages" element={<MessagesPage />} />
              <Route path="/messages/:id" element={<MessagesPage />} />
              <Route path="/about" element={<About />} />
              <Route path="/faq" element={<Faq />} />
              <Route path="/terms" element={<Terms />} />
              <Route path="/privacy" element={<Privacy />} />
              <Route path="/login" element={<Login />} />
              <Route path="/admin/reports" element={<AdminReports />} />
              <Route path="/forgot-password" element={<ForgotPassword />} />
              <Route path="/reset-password" element={<ResetPassword />} />
              <Route path="/email-verified" element={<EmailVerified />} />
              <Route path="/register" element={<Register />} />
            </Routes>
          </div>
          <Footer />

          {/* Outside the routes: the adviser is useful on any page, and it
              renders nothing at all unless the server has an API key. */}
          <GearAdviser />
        </div>
      </BrowserRouter>
    </AuthProvider>
  );
}

export default App;
